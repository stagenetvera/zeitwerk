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

            <td><?= h($inv['issue_date'] ?: '—') ?></td>
            <td><?= h($inv['due_date'] ?: '—') ?></td>
            <td><?= h($inv['status'] ?? '—') ?></td>
            <td class="text-end"><?= number_format((float)($inv['total_net'] ?? 0), 2, ',', '.') ?></td>
            <td class="text-end"><?= number_format((float)($inv['total_gross'] ?? 0), 2, ',', '.') ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary" href="<?= url('/invoices/edit.php') ?>?id=<?= (int)$inv['id'] ?>"><i class="bi bi-eye"></i>
    <span class="visually-hidden">Ansehen</span></a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= url('/invoices/export_xml.php') ?>?id=<?= (int)$inv['id'] ?>">XML</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="<?= $showCompany ? 8 : 7 ?>" class="text-center text-muted"><?= h($empty_message) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>