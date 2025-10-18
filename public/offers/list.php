<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
$user = auth_user();
$account_id = (int)$user['account_id'];

$stmt = $pdo->prepare('SELECT o.*, c.name AS company_name, ct.name AS contact_name, p.title AS project_title
  FROM offers o
  JOIN companies c ON c.id = o.company_id AND c.account_id = o.account_id
  LEFT JOIN contacts ct ON ct.id = o.contact_id AND ct.account_id = o.account_id
  LEFT JOIN projects p ON p.id = o.project_id AND p.account_id = o.account_id
  WHERE o.account_id = ?
  ORDER BY o.id DESC');
$stmt->execute([$account_id]);
$offers = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Angebote</h3>
  <a class="btn btn-primary" href="<?=url('/offers/new.php')?>">Neu</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Firma</th>
            <th>Kontakt</th>
            <th>Projekt</th>
            <th>Status</th>
            <th>Stundensatz</th>
            <th class="text-end">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($offers as $o): ?>
          <tr>
            <td><?=$o['id']?></td>
            <td><?=h($o['company_name'])?></td>
            <td><?=h($o['contact_name'] ?? '')?></td>
            <td><?=h($o['project_title'] ?? '')?></td>
            <td><?=h($o['status'])?></td>
            <td><?=h($o['hourly_rate'] ?? '')?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary" href="<?=url('/offers/edit.php')?>?id=<?=$o['id']?>"><i class="bi bi-pencil"></i>
              <span class="visually-hidden">Bearbeiten</span></a>
              <?php if ($o['status']==='offen'): ?>
                <form class="d-inline" method="post" action="<?=url('/offers/accept_post.php')?>">
                  <?=csrf_field()?>
                  <input type="hidden" name="id" value="<?=$o['id']?>">
                  <button class="btn btn-sm btn-success">Annehmen</button>
                </form>
              <?php endif; ?>
              <form class="d-inline" method="post" action="<?=url('/offers/delete.php')?>" onsubmit="return confirm('Dieses Angebot löschen?');">
                <?=csrf_field()?>
                <input type="hidden" name="id" value="<?=$o['id']?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i>
                    <span class="visually-hidden">Löschen</span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$offers): ?>
          <tr><td colspan="7" class="text-center text-muted">Noch keine Angebote.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
