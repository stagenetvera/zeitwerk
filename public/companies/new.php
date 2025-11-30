<?php
// public/companies/new.php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$return_to = pick_return_to('/companies/index.php');


// Account-Settings für Platzhalter/JS (z. B. Standard-MwSt)
$settings = get_account_settings($pdo, $account_id);
// Für "Neu" gibt es noch keine Firmen-Overrides → effektive Defaults = Account
[$eff_scheme, $eff_vat] = get_effective_tax_defaults($settings, null);
$acct_vat_js = number_format((float)$settings['default_vat_rate'], 2, '.', ''); // z.B. "19.00"

$err = null;

// Helper für Sticky-Form-Werte
$val = function($key, $default = '') {
  if (array_key_exists($key, $_POST)) {
    return htmlspecialchars((string)$_POST[$key], ENT_QUOTES, 'UTF-8');
  }
  return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
  $name    = trim($_POST['name'] ?? '');
  $address_line1 = trim($_POST['address_line1'] ?? '');
  $address_line2 = trim($_POST['address_line2'] ?? '');
  $address_line3 = trim($_POST['address_line3'] ?? '');
  $postal_code   = trim($_POST['postal_code']   ?? '');
  $city          = trim($_POST['city']          ?? '');
  $country_code  = strtoupper(trim($_POST['country_code'] ?? 'DE'));
  if ($country_code === '') {
      $country_code = 'DE';
  }

  $rate    = ($_POST['hourly_rate'] !== '' ? (float)str_replace(',', '.', (string)$_POST['hourly_rate']) : null);
  $vat_id  = trim($_POST['vat_id'] ?? '');
  $status  = $_POST['status'] ?? 'aktiv';

  // Steuer-Override (optional)
  $tax_scheme = isset($_POST['default_tax_scheme']) && $_POST['default_tax_scheme'] !== ''
    ? (string)$_POST['default_tax_scheme']
    : null;
  $valid_schemes = ['standard','tax_exempt','reverse_charge'];
  if ($tax_scheme !== null && !in_array($tax_scheme, $valid_schemes, true)) {
    $tax_scheme = null;
  }

  $vat_raw = $_POST['default_vat_rate'] ?? '';
  $vat_val = null;
  if ($vat_raw !== '') {
    $vat_val = (float)str_replace(',', '.', (string)$vat_raw);
    if ($vat_val < 0 || $vat_val > 100) { $vat_val = null; }
  }

  if ($name === '') {
    $err = 'Name ist erforderlich.';
  }

  if (!$err) {
    // Insert inkl. Steuer-Overrides
   $ins = $pdo->prepare('
      INSERT INTO companies
        (account_id,
        name,
        address,
        address_line1,
        address_line2,
        address_line3,
        postal_code,
        city,
        country_code,
        hourly_rate,
        vat_id,
        status,
        default_tax_scheme,
        default_vat_rate,
        invoice_intro_text,
        invoice_outro_text)
      VALUES
        (?,          -- account_id
        ?,          -- name
        ?,          -- address (Textblock)
        ?,          -- address_line1
        ?,          -- address_line2
        ?,          -- address_line3
        ?,          -- postal_code
        ?,          -- city
        ?,          -- country_code
        ?,          -- hourly_rate
        ?,          -- vat_id
        ?,          -- status
        ?,          -- default_tax_scheme
        ?,          -- default_vat_rate
        ?,          -- invoice_intro_text
        ?)          -- invoice_outro_text
  ');

  $ins->execute([
      $account_id,
      $name,
      $address,
      $address_line1,
      $address_line2,
      $address_line3,
      $postal_code,
      $city,
      $country_code,
      $rate,
      $vat_id,
      $status,
      $tax_scheme,   // kann NULL sein
      $vat_val,      // kann NULL sein
      $co_intro,     // ggf. '' oder NULL, je nach deinem Code
      $co_outro,
  ]);
    flash('Firma angelegt.', 'success');
    redirect($return_to);
    exit;
  }
}

// ---------- View ----------
require __DIR__ . '/../../src/layout/header.php';
?>
<div class="row">
  <div class="col-md-8 col-lg-7">
    <h3>Neue Firma</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <?= return_to_hidden($return_to) ?>
      <input type="hidden" name="action" value="save" />


      <div class="row g-3">
        <div class="col-md-12">
          <label class="form-label">Name / Firma</label>
          <input type="text" name="name" class="form-control"
                value="<?= $val('name') ?>" required>
        </div>

        <div class="col-12">
          <label class="form-label">Adresszeile 1 (Straße & Hausnummer)</label>
          <input type="text" name="address_line1" class="form-control"
                value="<?= $val('address_line1') ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Adresszeile 2</label>
          <input type="text" name="address_line2" class="form-control"
                value="<?= $val('address_line2') ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Adresszeile 3</label>
          <input type="text" name="address_line3" class="form-control"
                value="<?= $val('address_line3') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">PLZ</label>
          <input type="text" name="postal_code" class="form-control"
                value="<?= $val('postal_code') ?>">
        </div>

        <div class="col-md-8">
          <label class="form-label">Ort</label>
          <input type="text" name="city" class="form-control"
                value="<?= $val('city') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Land</label>
          <select name="country_code" class="form-select">
            <?php
            $cc = $val('country_code') ?: 'DE';
            $countries = [
              'DE' => 'Deutschland',
              'AT' => 'Österreich',
              'CH' => 'Schweiz',
              // bei Bedarf erweitern
            ];
            foreach ($countries as $code => $label):
            ?>
              <option value="<?=h($code)?>" <?=$cc === $code ? 'selected' : ''?>><?=h($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Stundensatz (€)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control" value="<?=$val('hourly_rate')?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Default-Steuerart (Firma)</label>
          <select
              name="default_tax_scheme"
              id="co_tax_scheme"
              class="form-select"
              data-rate-standard="<?= $acct_vat_js ?>"
              data-rate-tax-exempt="0.00"
              data-rate-reverse-charge="0.00">
            <?php $co_scheme = $_POST['default_tax_scheme'] ?? ''; ?>
            <option value="">
              — Account-Standard übernehmen (<?= h($eff_scheme) ?>) —
            </option>
            <option value="standard"       <?= $co_scheme==='standard'?'selected':'' ?>>standard (mit MwSt)</option>
            <option value="tax_exempt"     <?= $co_scheme==='tax_exempt'?'selected':'' ?>>steuerfrei</option>
            <option value="reverse_charge" <?= $co_scheme==='reverse_charge'?'selected':'' ?>>Reverse-Charge</option>
          </select>
          <div class="form-text">Leer lassen = Einstellungen des Accounts nutzen.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Default-Steuersatz (%)</label>
          <input
              type="number" step="0.01" min="0" max="100"
              name="default_vat_rate"
              id="co_vat_rate"
              class="form-control"
              value="<?=$val('default_vat_rate')?>"
              placeholder="<?= h(number_format((float)$settings['default_vat_rate'], 2, ',', '.')) ?>">
          <div class="form-text">
            Leer lassen = Account-Standard (<?= h(number_format((float)$settings['default_vat_rate'], 2, ',', '.')) ?> %).
          </div>
        </div>

        <script>
        (function(){
          var sel = document.getElementById('co_tax_scheme');
          var vat = document.getElementById('co_vat_rate');
          if (!sel || !vat) return;

          // Falls initial leer, anhand aktueller Auswahl vorbelegen
          if (!vat.value) {
            var initMap = {
              '':               sel.dataset.rateStandard,
              'standard':       sel.dataset.rateStandard,
              'tax_exempt':     sel.dataset.rateTaxExempt,
              'reverse_charge': sel.dataset.rateReverseCharge
            };
            if (initMap.hasOwnProperty(sel.value)) {
              vat.value = initMap[sel.value] || '0.00';
            }
          }

          // Bei Wechsel der Steuerart Steuersatz setzen (User kann danach überschreiben)
          sel.addEventListener('change', function(){
            var map = {
              '':               sel.dataset.rateStandard,
              'standard':       sel.dataset.rateStandard,
              'tax_exempt':     sel.dataset.rateTaxExempt,
              'reverse_charge': sel.dataset.rateReverseCharge
            };
            if (map.hasOwnProperty(sel.value)) {
              vat.value = map[sel.value] || '0.00';
            }
          });
        })();
        </script>

        <div class="col-md-4 mb-3">
          <label class="form-label">USt-ID</label>
          <input type="text" name="vat_id" class="form-control" value="<?=$val('vat_id')?>">
        </div>

        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php $stt = $_POST['status'] ?? 'aktiv'; ?>
            <option value="aktiv"         <?=$stt==='aktiv'?'selected':''?>>aktiv</option>
            <option value="abgeschlossen" <?=$stt==='abgeschlossen'?'selected':''?>>abgeschlossen</option>
          </select>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary">Speichern</button>
        <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
