<?php
/**
 * Reusable invoices table partial.
 *
 * Expects:
 * - $invoices (array of rows)
 * - $invoice_table_mode = 'without_company' | 'with_company' (optional, default 'without_company')
 * - $empty_message (optional, default: 'Keine Rechnungen.')
 *
 * When $invoice_table_mode === 'with_company' the table shows a "Firma" column and expects:
 *   - company_id, company_name in each row.
 */
if (!isset($invoice_table_mode)) $invoice_table_mode = 'without_company';
$showCompany = ($invoice_table_mode === 'with_company');
$empty_message = $empty_message ?? 'Keine Rechnungen.';

/* NEU: Kompatibilität – falls Aufrufer $rows statt $invoices benutzt */
if (!isset($invoices) && isset($rows) && is_array($rows)) {
  $invoices = $rows;
}

/* NEU: $return_to robust setzen (falls nicht vom Aufrufer gesetzt) */
if (!isset($return_to) || $return_to === null || $return_to === '') {
  if (function_exists('rt_current_path_and_query')) {
    $return_to = rt_current_path_and_query();
  } else {
    $return_to = $_SERVER['REQUEST_URI'] ?? '/invoices/index.php';
  }
}
?>

<div class="table-responsive">
  <table class="table table-striped table-hover mb-0">
    <thead>
      <tr>
        <th>#</th>
        <?php if ($showCompany): ?><th>Firma</th><?php endif; ?>
        <th>Rechnungsdatum</th>
        <th>Fällig</th>
        <th>Status</th>
        <th class="text-end">Netto</th>
        <th class="text-end">Brutto</th>
        <th class="text-end">Aktionen</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($invoices)): ?>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><?= h($inv['invoice_number'] ?: '—') ?></td>

            <?php if ($showCompany): ?>
              <td>
                <?php if (!empty($inv['company_id'])): ?>
                  <a href="<?= url('/companies/show.php') ?>?id=<?= (int)$inv['company_id'] ?>">
                    <?= h($inv['company_name'] ?? '—') ?>
                  </a>
                <?php else: ?>
                  <?= h($inv['company_name'] ?? '—') ?>
                <?php endif; ?>
              </td>
            <?php endif; ?>

            <td><?= h(_fmt_dmy($inv['issue_date'] ?: '—')) ?></td>
            <?php
            $badge_due_date = '';
            if ($inv["status"] != "bezahlt") {
              $dueDateStr = $inv['due_date'] ?? null;
              if (!empty($dueDateStr)) {
                  $dlTs = strtotime($dueDateStr);
                  if ($dlTs !== false) {
                      $today = strtotime('today');
                      if ($dlTs < $today) {
                          // Deadline bereits vorbei
                          $badge_due_date = 'badge bg-warning text-dark';
                          $diffDays = (int) floor(($today - $dlTs) / 86400);
                          // wenn schon 5 Tage überfällig
                          if ($diffDays > 5) {
                            $badge_due_date = 'badge bg-danger';
                          }


                      }
                  }
                }
              }
            ?>
            <td><?php if ($badge_due_date): ?>
              <span class="<?= $badge_due_date ?>">
                <?= h(_fmt_dmy($inv['due_date'] ?: '—')) ?>
              </span>
              <?php else : ?>
                <?= h(_fmt_dmy($inv['due_date'] ?: '—')) ?>
              <?php endif ?>

            </td>
            <td><?= h($inv['status'] ?? '—') ?></td>
            <td class="text-end"><?= number_format((float)($inv['total_net'] ?? 0), 2, ',', '.') ?></td>
            <td class="text-end"><?= number_format((float)($inv['total_gross'] ?? 0), 2, ',', '.') ?></td>
            <td class="text-end">
              <form method="post" action="<?= url('/invoices/edit.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></button>
                <span class="visually-hidden">Ansehen</span>
              </form>

              <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('/invoices/pdf.php?id=' . $inv['id'])) ?>">PDF</a>
              <?php
                $st = $inv['status'] ?? 'in_vorbereitung';
                $has_no_number = empty($inv['invoice_number']);
                $can_delete = ($st === 'in_vorbereitung' && $has_no_number);
                $can_cancel = in_array($st, ['gestellt','gemahnt','bezahlt', 'ausgebucht'], true); // „storniert“ schon storniert → deaktivieren

              ?>
              <form method="post" action="<?= url('/invoices/cancel.php') ?>" class="d-inline"
                    onsubmit="return confirm('Rechnung wirklich stornieren?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $can_cancel ? '' : 'disabled' ?>>Stornieren</button>
              </form>

              <form method="post" action="<?= url('/invoices/delete.php') ?>" class="d-inline"
                                  onsubmit="return confirm('Rechnung wirklich löschen?');">
                <?= csrf_field() ?>
                <?= return_to_hidden($return_to) ?>
                <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $can_delete ? '' : 'disabled' ?>>
                  <i class="bi bi-trash"></i>
                </button>
              </form>



            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="<?= $showCompany ? 8 : 7 ?>" class="text-center text-muted"><?= h($empty_message) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>