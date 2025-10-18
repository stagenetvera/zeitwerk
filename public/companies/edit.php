<?php
// public/companies/edit.php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/lib/flash.php';

require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$return_to = pick_return_to('/companies/index.php');
$settings  = get_account_settings($pdo, $account_id);

// ---------- Input & Datensatz laden ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  // noch kein Output – also plain Fehler + Exit
  http_response_code(400);
  echo 'Ungültige Firmen-ID.';
  exit;
}
$st = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$st->execute([$id, $account_id]);
$company = $st->fetch();
if (!$company) {
  http_response_code(404);
  echo 'Firma nicht gefunden.';
  exit;
}

[$eff_scheme, $eff_vat] = get_effective_tax_defaults($settings, $company ?? null);
$err = null;

// ---------- POST (Update) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name    = trim($_POST['name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $rate    = ($_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null);
  $vat     = trim($_POST['vat_id'] ?? '');
  $status  = $_POST['status'] ?? 'aktiv';

  // Fallback-Mapping alter Werte
  if ($status === 'laufend') $status = 'aktiv';

  if ($name === '') {
    $err = 'Name ist erforderlich.';
  }

  // Steuer-Einstellungen (optional firmenspezifischer Override)
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

  if (!$err) {
    $upd = $pdo->prepare('UPDATE companies
                          SET name = ?, address = ?, hourly_rate = ?, vat_id = ?, status = ?, default_tax_scheme = ?, default_vat_rate = ?
                          WHERE id = ? AND account_id = ?');
    $upd->execute([$name, $address, $rate, $vat, $status, $tax_scheme, $vat_val, $id, $account_id]);

    flash('Firma gespeichert.', 'success');
    redirect($return_to);
    exit;
  } else {
    // Felder für Re-Render füllen
    $company['name']        = $name;
    $company['address']     = $address;
    $company['hourly_rate'] = $rate;
    $company['vat_id']      = $vat;
    $company['status']      = $status;
    // Hinweis: $company['default_*'] lässt du wie geladen; Anzeige nutzt unten $co_*.
  }
}

// ---------- Ab hier erst HTML ausgeben ----------
require __DIR__ . '/../../src/layout/header.php';

// Effektive Werte für Platzhalter/Hilfe
[$eff_scheme, $eff_vat] = get_effective_tax_defaults($settings, $company ?? null);
$co_scheme   = $company['default_tax_scheme'] ?? null; // NULL = kein Override
$co_vat      = $company['default_vat_rate']   ?? null; // NULL = kein Override
$acct_vat_js = number_format((float)$settings['default_vat_rate'], 2, '.', ''); // z.B. "19.00"
?>
<div class="row">
  <div class="col-md-8 col-lg-7">
    <h3>Firma bearbeiten</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$company['id'] ?>">
      <?= return_to_hidden($return_to) ?>

      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" value="<?= h($company['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Adresse</label>
        <textarea name="address" class="form-control" rows="3"><?= h($company['address'] ?? '') ?></textarea>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Stundensatz (€)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control"
                 value="<?= $company['hourly_rate'] !== null ? h((float)$company['hourly_rate']) : '' ?>">
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
              value="<?= $co_vat !== null ? h(number_format((float)$co_vat, 2, '.', '')) : '' ?>"
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
          <input type="text" name="vat_id" class="form-control" value="<?= h($company['vat_id'] ?? '') ?>">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php $stt = $company['status'] ?? 'aktiv'; ?>
            <option value="aktiv"         <?= $stt==='aktiv'?'selected':'' ?>>aktiv</option>
            <option value="abgeschlossen" <?= $stt==='abgeschlossen'?'selected':'' ?>>abgeschlossen</option>
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