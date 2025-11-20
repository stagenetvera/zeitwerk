<?php
require __DIR__ . '/../src/layout/header.php';
csrf_check();
$msg = null; $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  if ($name && $email && $pass) {
    if (register_user($pdo, $name, $email, $pass)) {
      redirect(url('/login.php'));
      $msg = "Registrierung erfolgreich. Bitte einloggen.";
    } else {
      $err = "Registrierung fehlgeschlagen (E-Mail evtl. bereits vergeben).";
    }
  } else {
    $err = "Bitte alle Felder ausfüllen.";
  }
}
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <h3>Registrieren</h3>
    <?php if ($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">E-Mail</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Passwort</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn btn-primary">Konto anlegen</button>
      <a class="btn btn-link" href="<?=url('/login.php')?>">Zum Login</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
