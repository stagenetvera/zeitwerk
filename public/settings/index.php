<?php
// public/settings/index.php
require __DIR__ . '/../../src/layout/header.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/settings.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$err = null; $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    save_account_settings($pdo, $account_id, $_POST);
    $ok = 'Einstellungen wurden gespeichert.';
  } catch (Throwable $e) {
    $err = 'Konnte Einstellungen nicht speichern.';
  }
}

$set = get_account_settings($pdo, $account_id);

function hurl($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Einstellungen</h3>
</div>

<?php if ($ok): ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

<form method="post" class="card">
  <div class="card-body">
    <?= csrf_field() ?>

    <h5 class="mb-3">Rechnungen</h5>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Rechnungsnummern-Schema</label>
        <input type="text" class="form-control" name="invoice_number_pattern"
               value="<?= h($set['invoice_number_pattern']) ?>">
        <div class="form-text">
          Platzhalter: <code>{YYYY}</code>, <code>{YY}</code>, <code>{MM}</code>, <code>{DD}</code>, <code>{SEQ}</code> (fortlaufend).
          Beispiel: <code>RE-{YYYY}-{SEQ}</code>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Nächste Sequenz</label>
        <input type="number" class="form-control" name="invoice_next_seq"
               value="<?= (int)$set['invoice_next_seq'] ?>" min="1">
      </div>
      <div class="col-md-3">
        <label class="form-label">Default-Fälligkeit (Tage)</label>
        <input type="number" class="form-control" name="default_due_days"
               value="<?= (int)$set['default_due_days'] ?>" min="0">
        <div class="form-text">0 = gleiches Datum wie Rechnungsdatum</div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Default-Steuersatz (%)</label>
        <input type="number" step="0.01" class="form-control" name="default_vat_rate"
               value="<?= h(number_format((float)$set['default_vat_rate'], 2, '.', '')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Default-Steuerart</label>
        <select class="form-select" name="default_tax_scheme">
          <?php
          $opts = [
            'standard'       => 'Standard (MwSt berechnen)',
            'tax_exempt'     => 'Steuerfrei (0 %)',
            'reverse_charge' => 'Reverse Charge (0 %, Hinweis erforderlich)',
          ];
          foreach ($opts as $k=>$label):
          ?>
            <option value="<?= h($k) ?>" <?= $set['default_tax_scheme']===$k?'selected':'' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label">Einleitender Text auf Rechnungen</label>
        <textarea class="form-control" name="invoice_intro_text" rows="4"><?= h($set['invoice_intro_text']) ?></textarea>
        <div class="form-text">Wird bei neuen Rechnungen als Standardtext verwendet (Export/Vorlage).</div>
      </div>

      <hr class="my-3">

      <h5 class="mb-3">Absender & Bankverbindung</h5>

      <div class="col-md-6">
        <label class="form-label">Absenderadresse (für Rechnungen)</label>
        <textarea class="form-control" name="sender_address" rows="5" placeholder="Firma / Name&#10;Straße Hausnr.&#10;PLZ Ort&#10;Land"><?= h($set['sender_address']) ?></textarea>
      </div>
      <div class="col-md-3">
        <label class="form-label">IBAN</label>
        <input type="text" class="form-control" name="bank_iban" value="<?= h($set['bank_iban']) ?>"
               placeholder="DE..">
      </div>
      <div class="col-md-3">
        <label class="form-label">BIC</label>
        <input type="text" class="form-control" name="bank_bic" value="<?= h($set['bank_bic']) ?>"
               placeholder="MARKDEF...">
      </div>
    </div>
  </div>

  <div class="card-footer d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?= hurl(url('/dashboard/index.php')) ?>">Abbrechen</a>
    <button class="btn btn-primary">Speichern</button>
  </div>
</form>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>