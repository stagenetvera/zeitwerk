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
        <label class="form-label">Länge für {SEQ}</label>
        <input type="number" name="invoice_seq_pad" min="1" max="12"
              class="form-control"
              value="<?= h((int)($set['invoice_seq_pad'] ?? 5)) ?>">
        <div class="form-text">Anzahl Ziffern, mit führenden Nullen.</div>
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

      <?php
        // Zahl als JS-kompatible Dezimalzahl vorbereiten (Punkt statt Komma)
        $as_std_rate_js = '19.00';
        $as_scheme = $set['default_tax_scheme'] ?? 'standard';
      ?>
      <div class="col-md-3">
        <label class="form-label">Default-Steuerart</label>
        <select
          name="default_tax_scheme"
          id="as_tax_scheme"
          class="form-select"
          data-rate-standard="<?= $as_std_rate_js ?>"
          data-rate-tax-exempt="0.00"
          data-rate-reverse-charge="0.00"
        >
          <option value="standard"       <?= $as_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
          <option value="tax_exempt"     <?= $as_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
          <option value="reverse_charge" <?= $as_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
        </select>
        <div class="form-text">Beim Wechsel der Steuerart wird der Steuersatz unten automatisch vorbelegt.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Default-Steuersatz (%)</label>
        <input
          type="number"
          step="0.01"
          min="0"
          max="100"
          name="default_vat_rate"
          id="as_vat_rate"
          class="form-control"
          value="<?= isset($set['default_vat_rate']) ? h(number_format((float)$set['default_vat_rate'], 2, '.', '')) : '' ?>"
        >
        <div class="form-text">Wird durch die Steuerart vorbelegt, kann aber jederzeit überschrieben werden.</div>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var sel = document.getElementById('as_tax_scheme');
          var vat = document.getElementById('as_vat_rate');
          if (!sel || !vat) return;

          // Defaults aus den data-Attributen des Selects lesen
          var defaults = {
            standard:       sel.dataset.rateStandard       || '19.00',
            tax_exempt:     sel.dataset.rateTaxExempt      || '0.00',
            reverse_charge: sel.dataset.rateReverseCharge  || '0.00'
          };

          function applyByScheme() {
            var scheme = sel.value;
            // Steuerart führt → Feld aktiv mit Default belegen (User kann danach überschreiben)
            vat.value = (defaults[scheme] != null ? defaults[scheme] : defaults.standard);
          }

          // Beim Wechsel der Steuerart immer vorbelegen
          sel.addEventListener('change', applyByScheme);
          sel.addEventListener('input',  applyByScheme); // falls manche Browser 'input' statt 'change' feuern

          // Beim ersten Laden nur dann setzen, wenn das Feld leer ist (bestehende Werte nicht überschreiben)
          if (!vat.value) applyByScheme();
        });
        </script>

      <div class="col-md-3">
        <label class="form-label">Minuten-Rundung</label>
        <div class="input-group">
          <input
            type="number"
            name="invoice_round_minutes"
            min="0"
            max="60"
            step="1"
            class="form-control"
            value="<?= (int)($set['invoice_round_minutes'] ?? 0) ?>">
          <span class="input-group-text">Min</span>
        </div>
        <div class="form-text">
          0 = keine Rundung. &gt;0: Nach Summenbildung je Position auf N Minuten <em>aufrunden</em> (ceil).
          Typische Werte: 5, 6, 10, 15 …
        </div>
      </div>


      <div class="mb-3">
        <label class="form-label">Standard-Einleitungstext</label>
        <textarea class="form-control" name="invoice_intro_text" rows="3"><?= h($set['invoice_intro_text'] ?? '') ?></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label">Standard-Schlussformel</label>
        <textarea class="form-control" name="invoice_outro_text" rows="3"><?= h($set['invoice_outro_text'] ?? '') ?></textarea>
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