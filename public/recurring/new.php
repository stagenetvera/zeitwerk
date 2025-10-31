<?php
// public/recurring/new.php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/utils.php'; // dec()
require_login(); csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
if ($company_id <= 0) {
  flash('Ungültige Firma.', 'danger');
  redirect(url('/companies/index.php'));
  exit;
}

// Firma prüfen
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE id=? AND account_id=?');
$cs->execute([$company_id, $account_id]);
$company = $cs->fetch();
if (!$company) {
  flash('Firma nicht gefunden.', 'danger');
  redirect(url('/companies/index.php'));
  exit;
}

$settings = get_account_settings($pdo, $account_id);
$def_vat  = number_format((float)($settings['default_vat_rate'] ?? 19.00), 2, '.', '');

$err = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save') {
  $tpl  = trim((string)($_POST['description_tpl'] ?? ''));
  $qty  = (float)dec($_POST['quantity'] ?? '1');
  $unit = (float)dec($_POST['unit_price'] ?? '0');
  $sch  = (string)($_POST['tax_scheme'] ?? 'standard');
  $vat  = (float)dec($_POST['vat_rate'] ?? $def_vat);
  if ($sch !== 'standard') $vat = 0.0;

  $iu   = (string)($_POST['interval_unit'] ?? 'month');
  $ic   = max(1, (int)($_POST['interval_count'] ?? 1));
  $from = (string)($_POST['start_date'] ?? date('Y-m-01'));
  $to   = $_POST['end_date'] ?? null; if ($to === '') $to = null;
  $active = isset($_POST['active']) ? 1 : 0;

  if ($tpl === '') {
    $err = 'Bitte Bezeichnung (Template) angeben.';
  } else {
    $ins = $pdo->prepare("
      INSERT INTO recurring_items
        (account_id, company_id, description_tpl, quantity, unit_price,
         tax_scheme, vat_rate, interval_unit, interval_count, start_date, end_date, active)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([
      $account_id, $company_id, $tpl, max(0,$qty), max(0,$unit),
      $sch, $vat, $iu, $ic, $from, $to, $active
    ]);
    flash('Wiederkehrende Position angelegt.', 'success');
    redirect(url('/companies/show.php').'?id='.$company_id);
    exit;
  }
}

require __DIR__ . '/../../src/layout/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Wiederkehrende Position anlegen</h3>
  <div>
    <a class="btn btn-outline-secondary" href="<?= url('/companies/show.php') ?>?id=<?= (int)$company_id ?>">Zur Firma</a>
  </div>
</div>

<?php flash_render_bootstrap(); ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="company_id" value="<?= (int)$company_id ?>">

  <div class="col-md-6">
    <label class="form-label">Bezeichnung (Template)</label>
    <input name="description_tpl" class="form-control" placeholder="z. B. Wartung {period}" required>
    <div class="form-text">Platzhalter: {from}, {to}, {period}, {month}, {year}</div>
  </div>
  <div class="col-md-3">
    <label class="form-label">Menge</label>
    <input type="number" step="0.001" name="quantity" class="form-control" value="1.000">
  </div>
  <div class="col-md-3">
    <label class="form-label">Einzelpreis (€)</label>
    <input type="number" step="0.01" name="unit_price" class="form-control" value="0.00">
  </div>

  <div class="col-md-3">
    <label class="form-label">Steuerart</label>
    <select name="tax_scheme" class="form-select ri-tax-sel" data-rate-standard="<?= h($def_vat) ?>">
      <option value="standard" selected>standard (mit MwSt)</option>
      <option value="tax_exempt">steuerfrei</option>
      <option value="reverse_charge">Reverse-Charge</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">MwSt %</label>
    <input type="number" step="0.01" name="vat_rate" class="form-control ri-vat" value="<?= h($def_vat) ?>">
    <div class="form-text">Bei steuerfrei/Reverse-Charge automatisch 0,00.</div>
  </div>

  <div class="col-md-3">
    <label class="form-label">Intervall</label>
    <select name="interval_unit" class="form-select">
      <option value="month" selected>monatlich</option>
      <option value="quarter">quartalsweise</option>
      <option value="year">jährlich</option>
      <option value="week">wöchentlich</option>
      <option value="day">täglich</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">alle (Anzahl)</label>
    <input type="number" min="1" step="1" name="interval_count" class="form-control" value="1">
  </div>

  <div class="col-md-3">
    <label class="form-label">Laufzeit von</label>
    <input type="date" name="start_date" class="form-control" value="<?= h(date('Y-m-01')) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Laufzeit bis</label>
    <input type="date" name="end_date" class="form-control">
    <div class="form-text">leer = unbegrenzt</div>
  </div>

  <div class="col-md-3 form-check align-self-end ms-2">
    <input class="form-check-input" type="checkbox" value="1" name="active" id="ri-active" checked>
    <label class="form-check-label" for="ri-active">aktiv</label>
  </div>

  <div class="col-12 d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="<?= url('/companies/show.php') ?>?id=<?= (int)$company_id ?>">Abbrechen</a>
    <button class="btn btn-primary">Anlegen</button>
  </div>
</form>

<script>
(function(){
  // MwSt-Umschaltung je Steuerart
  document.addEventListener('change', function(e){
    const sel = e.target.closest('.ri-tax-sel');
    if (!sel) return;
    const form = sel.form;
    const vat = form && form.querySelector('.ri-vat');
    if (!vat) return;
    if (sel.value === 'standard') {
      vat.value = sel.dataset.rateStandard || '19.00';
    } else {
      vat.value = '0.00';
    }
  });
})();
</script>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>