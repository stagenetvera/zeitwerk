<?php
require __DIR__ . '/../src/layout/header.php';
csrf_check();
$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  if (login($pdo, $email, $pass)) {
    redirect('/dashboard/index.php');
  } else {
    $err = "Login fehlgeschlagen.";
  }
}
?>
<div class="row justify-content-center">
  <div class="col-md-4">
    <h3>Login</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">E-Mail</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Passwort</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn btn-primary">Einloggen</button>
      <a class="btn btn-link" href="<?=url('/register.php')?>">Registrieren</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
