<?php
// public/projects/new.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

$err = null;
// --- optionaler Firmen-Kontext (aus Firmen-Ansicht) ---
$prefill_company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
$prefill_company = null;
if ($prefill_company_id > 0) {
  $cs = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? AND account_id = ?');
  $cs->execute([$prefill_company_id, $account_id]);
  $prefill_company = $cs->fetch();
  if (!$prefill_company) {
    $prefill_company_id = 0; // ungültige ID ignorieren
  }
}

$return_to = pick_return_to('/companies/show.php?id='.$prefill_company_id);


// POST-Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST'  && isset($_POST["action"]) && $_POST["action"] == "save") {
  $title = trim($_POST['title'] ?? '');
  $status = $_POST['status'] ?? 'offen';
  $project_rate = ($_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null);

  // company_id aus POST oder prefill – aber wenn prefill gesetzt ist, erzwingen wir ihn serverseitig
  $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
  if ($prefill_company_id > 0) {
    $company_id = $prefill_company_id;
  }

  // Validierung
  if ($title === '') {
    $err = 'Titel ist erforderlich.';
  } elseif ($company_id <= 0) {
    $err = 'Bitte eine Firma wählen.';
  } else {
    // gehört die Firma zum Account?
    $chk = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND account_id = ?');
    $chk->execute([$company_id, $account_id]);
    if (!$chk->fetch()) {
      $err = 'Ungültige Firma.';
    }
  }

  if (!$err) {
    $ins = $pdo->prepare('INSERT INTO projects (account_id, company_id, title, status, hourly_rate) VALUES (?,?,?,?,?)');
    $ins->execute([$account_id, $company_id, $title, $status, $project_rate]);
    flash('Projekt angelegt.', 'success');
    redirect($return_to);
  }
}

// Für das Formular: Firmenliste nur laden, wenn KEIN prefill aktiv ist
$companies = [];
if ($prefill_company_id === 0) {
  $cl = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
  $cl->execute([$account_id]);
  $companies = $cl->fetchAll();
}
?>
<div class="row">
  <div class="col-md-8 col-lg-7">
    <h3>Neues Projekt</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post">
      <?=csrf_field()?>
      <?= return_to_hidden($return_to) ?>
      <input type="hidden" name="action" value="save">

      <?php if ($prefill_company_id > 0 && $prefill_company): ?>
        <!-- Feste Firma (aus Firmen-Ansicht) -->
        <div class="mb-3">
          <label class="form-label">Firma</label>
          <div class="form-control-plaintext fw-semibold"><?=h($prefill_company['name'])?></div>
          <input type="hidden" name="company_id" value="<?=$prefill_company['id']?>">
        </div>
      <?php else: ?>
        <!-- Freie Firmenwahl -->
        <div class="mb-3">
          <label class="form-label">Firma</label>
          <select name="company_id" class="form-select" required>
            <option value="">– bitte wählen –</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label">Titel</label>
        <input type="text" name="title" class="form-control" required>
      </div>

      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="offen">offen</option>
            <option value="angeboten">angeboten</option>
            <option value="abgeschlossen">abgeschlossen</option>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Stundensatz (Projekt)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control" placeholder="leer = Firmenrate">
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