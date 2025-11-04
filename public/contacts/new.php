<?php
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/lib/flash.php';
require_once __DIR__ . '/../../src/lib/contacts.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;

$return_to = pick_return_to('/companies/show.php?id='.$company_id);

// Firma prüfen (Mandantenschutz)
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? AND account_id = ?');
$cs->execute([$company_id,$account_id]);
$company = $cs->fetch();
if (!$company) { echo '<div class="alert alert-danger">Firma nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST'   && isset($_POST["action"]) && $_POST["action"] == "save") {

  $norm = contacts_normalize_input($_POST);

  if ($norm["last_name"]) {
    $ins = $pdo->prepare("
      INSERT INTO contacts
        (account_id, company_id, email, phone, phone_alt, department,
        salutation, first_name, last_name, greeting_line, is_invoice_addressee)
      VALUES (?,?,?,?, ?,?,?,?,?,?,?)
    ");
    $ins->execute([
      $account_id,
      (int)$_POST['company_id'],
      $norm['email'],
      $norm['phone'],
      $norm['phone_alt'],
      $norm['department'],
      $norm['salutation'],
      $norm['first_name'],
      $norm['last_name'],
      $norm['greeting_line'],
      $norm['is_invoice_addressee'],
    ]);
    flash('Ansprechpartner angelegt.', 'success');

    redirect($return_to);
  } else {
    $err = 'Nachname ist erforderlich.';
  }
}
require __DIR__ . '/../../src/layout/header.php';

?>
<div class="row">
  <div class="col-md-7">
    <h3>Neuer Ansprechpartner – <?=h($company['name'])?></h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <?= return_to_hidden($return_to) ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="company_id" value="<?=$company['id']?>">

      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">Anrede</label>
          <select name="salutation" class="form-select">
            <option value="">– keine –</option>
            <option value="frau" <?= (($_POST['salutation'] ?? '')==='frau')?'selected':'' ?>>Frau</option>
            <option value="herr" <?= (($_POST['salutation'] ?? '')==='herr')?'selected':'' ?>>Herr</option>
            <option value="div"  <?= (($_POST['salutation'] ?? '')==='div')?'selected':''  ?>>Divers</option>
          </select>
        </div>

        <div class="col-md-4 mb-3">
          <label class="form-label">Vorname</label>
          <input type="text" name="first_name" class="form-control"
                value="<?= h($_POST['first_name'] ?? '') ?>">
        </div>

        <div class="col-md-5 mb-3">
          <label class="form-label">Nachname</label>
          <input type="text" name="last_name" class="form-control"
                value="<?= h($_POST['last_name'] ?? '') ?>">
        </div>

        <div class="col-12 mb-3">
          <label class="form-label">Abteilung</label>
          <input type="text"
                class="form-control"
                name="department"
                value="<?= h($_POST['department'] ?? '') ?>">
        </div>
        <div class="col-12 mb-3">
          <label class="form-label">Begrüßungszeile</label>
          <input type="text" name="greeting_line" class="form-control"
                placeholder="z. B. Sehr geehrte Frau Müller"
                value="<?= h($_POST['greeting_line'] ?? '') ?>">
          <div class="form-text">
            Leer lassen, um automatisch eine passende Begrüßung (Anrede + Name) zu erzeugen.
          </div>
        </div>

        <div class="col-12 mb-3 form-check">
          <input class="form-check-input" type="checkbox" id="is_invoice_addressee" name="is_invoice_addressee"
                value="1" <?= !empty($_POST['is_invoice_addressee'])?'checked':'' ?>>
          <label class="form-check-label" for="is_invoice_addressee">
            Rechnungen an diesen Ansprechpartner richten
          </label>
        </div>
      </div>


      <div class="mb-3">
        <label class="form-label">E-Mail</label>
        <input type="email" name="email" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Telefon</label>
        <input type="text" name="phone" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Telefon 2</label>
        <input type="text"
              class="form-control"
              name="phone_alt"
              value="<?= h($_POST['phone_alt'] ?? '') ?>">
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
