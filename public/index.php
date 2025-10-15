<?php require __DIR__ . '/../src/layout/header.php'; ?>
<div class="p-5 mb-4 bg-white border rounded-3">
  <div class="container-fluid py-5">
    <h1 class="display-5 fw-bold">Willkommen bei Zeitwerk</h1>
    <p class="col-md-8 fs-4">Tracke Arbeitszeiten, verwalte Aufgaben und erstelle Rechnungsentwürfe.</p>
    <?php if (!auth_user()): ?>
      <a class="btn btn-primary btn-lg" href="<?=url('/register.php')?>">Jetzt starten</a>
    <?php else: ?>
      <a class="btn btn-primary btn-lg" href="<?=url('/dashboard/index.php')?>">Zum Dashboard</a>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
