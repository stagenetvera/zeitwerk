<?php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/contacts.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare('SELECT * FROM contacts WHERE id = ? AND account_id = ?');
$st->execute([$id,$account_id]);
$contact = $st->fetch();
if (!$contact) { echo '<div class="alert alert-danger">Ansprechpartner nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }
$company_id = (int)$contact['company_id'];

$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Firma für Titel
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$company_id,$account_id]);
$company = $cs->fetch();

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $norm = contacts_normalize_input($_POST);
  if ($norm["last_name"]) {

    $upd = $pdo->prepare("
      UPDATE contacts
        SET email = ?,
            phone = ?,
            salutation = ?,
            first_name = ?,
            last_name  = ?,
            greeting_line = ?,
            is_invoice_addressee = ?
      WHERE id = ? AND account_id = ?
    ");
    $upd->execute([
      trim((string)($_POST['email'] ?? '')),
      trim((string)($_POST['phone'] ?? '')),
      $norm['salutation'],
      $norm['first_name'],
      $norm['last_name'],
      $norm['greeting_line'],
      $norm['is_invoice_addressee'],
      (int)$id,
      (int)$account_id,
    ]);
    flash('Ansprechpartner gespeichert.', 'success');
    redirect($return_to);
    $ok = 'Gespeichert.';
    $st->execute([$id,$account_id]); $contact = $st->fetch();
  } else {
    $err = 'Nachname ist erforderlich.';
  }
}
require __DIR__ . '/../../src/layout/header.php';

?>
<div class="row">
  <div class="col-md-7">
    <h3>Ansprechpartner bearbeiten – <?=h($company['name'] ?? '')?></h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <?= return_to_hidden($return_to) ?>
      <?php
      $sal_cur = $contact['salutation'] ?? '';
      $fn_cur  = $contact['first_name'] ?? '';
      $ln_cur  = $contact['last_name']  ?? '';
      $gr_cur  = $contact['greeting_line'] ?? '';
      $inv_cur = !empty($contact['is_invoice_addressee']);
      ?>
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Anrede</label>
          <select name="salutation" class="form-select">
            <option value="">– keine –</option>
            <option value="frau" <?= $sal_cur==='frau'?'selected':'' ?>>Frau</option>
            <option value="herr" <?= $sal_cur==='herr'?'selected':'' ?>>Herr</option>
            <option value="div"  <?= $sal_cur==='div' ?'selected':'' ?>>Divers</option>
          </select>
        </div>

        <div class="col-md-4 mb-3">
          <label class="form-label">Vorname</label>
          <input type="text" name="first_name" class="form-control" value="<?= h($fn_cur) ?>">
        </div>

        <div class="col-md-5 mb-3">
          <label class="form-label">Nachname</label>
          <input type="text" name="last_name" class="form-control" value="<?= h($ln_cur) ?>">
        </div>

        <div class="col-12 mb-3">
          <label class="form-label">Begrüßungszeile</label>
          <input type="text" name="greeting_line" class="form-control"
                placeholder="z. B. Sehr geehrte Frau Müller"
                value="<?= h($gr_cur) ?>">
        </div>

        <div class="col-12 mb-3 form-check">
          <?php $chk = $inv_cur ? 'checked' : ''; ?>
          <input class="form-check-input" type="checkbox" id="is_invoice_addressee" name="is_invoice_addressee" value="1" <?= $chk ?>>
          <label class="form-check-label" for="is_invoice_addressee">
            Rechnungen an diesen Ansprechpartner richten
          </label>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">E-Mail</label>
        <input type="email" name="email" class="form-control" value="<?=h($contact['email'] ?? '')?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Telefon</label>
        <input type="text" name="phone" class="form-control" value="<?=h($contact['phone'] ?? '')?>">
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>
  </div>
</div>


<script>
(function(){
  const sal   = document.querySelector('select[name="salutation"]');
  const first = document.querySelector('input[name="first_name"]');
  const last  = document.querySelector('input[name="last_name"]');
  const greet = document.querySelector('input[name="greeting_line"]');
  if (!sal || !first || !last || !greet) return;

  function generate(){
    if (greet.value.trim() !== '') return; // User hat bereits etwas geschrieben
    const s = (sal.value||'').toLowerCase();
    const f = (first.value||'').trim();
    const l = (last.value||'').trim();

    let text = 'Guten Tag';
    if (s === 'frau' && l) text = 'Sehr geehrte Frau ' + l;
    else if (s === 'herr' && l) text = 'Sehr geehrter Herr ' + l;
    else if ((f||l)) text = 'Guten Tag ' + (f + ' ' + l).trim();

    greet.placeholder = text;
  }

  ['change','input'].forEach(evt=>{
    sal.addEventListener(evt, generate);
    first.addEventListener(evt, generate);
    last.addEventListener(evt, generate);
  });
  generate();
})();
</script>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
