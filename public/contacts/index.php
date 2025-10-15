<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];

// Kontakte + zugehörige Firma laden
$stmt = $pdo->prepare('SELECT ct.*, co.name AS company_name
  FROM contacts ct
  JOIN companies co ON co.id = ct.company_id AND co.account_id = ct.account_id
  WHERE ct.account_id = ?
  ORDER BY co.name, ct.name');
$stmt->execute([$account_id]);
$contacts = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Ansprechpartner</h3>
  <a class="btn btn-primary" href="<?=url('/contacts/new.php')?>">Neu</a>
  </div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Name</th>
            <th>Firma</th>
            <th>E-Mail</th>
            <th>Telefon</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($contacts as $c): ?>
          <tr>
            <td><?=h($c['name'])?></td>
            <td><?=h($c['company_name'])?></td>
            <td><?=h($c['email'] ?? '')?></td>
            <td><?=h($c['phone'] ?? '')?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary" href="<?=url('/contacts/edit.php')?>?id=<?=$c['id']?>">Bearbeiten</a>
              <form class="d-inline" method="post" action="<?=url('/contacts/delete.php')?>" onsubmit="return confirm('Diesen Ansprechpartner wirklich löschen?');">
                <?=csrf_field()?>
                <input type="hidden" name="id" value="<?=$c['id']?>">
                <button class="btn btn-sm btn-outline-danger">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$contacts): ?>
          <tr><td colspan="5" class="text-center text-muted">Noch keine Ansprechpartner.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  </div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
