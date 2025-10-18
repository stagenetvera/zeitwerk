<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user       = auth_user();
$account_id = (int)$user['account_id'];

require_once __DIR__ . '/../../src/lib/settings.php';
require_once __DIR__ . '/../../src/lib/invoice_number.php';

$err=null; $ok=null;

$pattern = settings_get($pdo, $account_id, 'invoice_no_pattern', 'INV-{YYYY}-{NNNN}');
$next    = settings_get_int($pdo, $account_id, 'invoice_no_next', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $new_pattern = trim($_POST['pattern'] ?? '');
  if ($new_pattern === '') $new_pattern = 'INV-{YYYY}-{NNNN}';
  settings_set($pdo, $account_id, 'invoice_no_pattern', $new_pattern);
  // Optional: manuellen Counter erlauben
  if (isset($_POST['next_counter']) && $_POST['next_counter'] !== '') {
    $nc = max(1, (int)$_POST['next_counter']);
    settings_set($pdo, $account_id, 'invoice_no_next', (string)$nc);
  }
  $pattern = $new_pattern;
  $next    = settings_get_int($pdo, $account_id, 'invoice_no_next', 1);
  $ok = 'Einstellungen gespeichert.';
}

$today = new DateTimeImmutable('today');
$preview = [
  inv_format_number($pattern, $next, $today),
  inv_format_number($pattern, $next+1, $today),
  inv_format_number($pattern, $next+2, $today),
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Rechnungsnummern</h3>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?=h($ok)?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Schema</label>
        <input type="text" name="pattern" class="form-control" value="<?= h($pattern) ?>">
        <div class="form-text">
          Platzhalter: <code>{YYYY}</code> <code>{YY}</code> <code>{MM}</code> <code>{DD}</code>,
          Zähler: <code>{N}</code>, <code>{NN}</code>, <code>{NNNN}</code>, <code>{N:5}</code>.
          Beispiele: <code>RE-{YYYY}-{NNNN}</code>, <code>INV-{YY}{MM}-{N:6}</code>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Nächster Zähler</label>
        <input type="number" name="next_counter" min="1" class="form-control" value="<?= (int)$next ?>">
        <div class="form-text">Nur ändern, wenn du den Lauf fortführen oder anpassen willst.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Vorschau</label>
        <ul class="mb-0">
          <?php foreach ($preview as $p): ?><li><code><?= h($p) ?></code></li><?php endforeach; ?>
        </ul>
      </div>

      <button class="btn btn-primary">Speichern</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../src/layout/footer.php'; ?>