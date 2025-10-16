<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();
$user = auth_user();
$account_id = (int)$user['account_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare('SELECT * FROM offers WHERE id = ? AND account_id = ?');
$st->execute([$id,$account_id]);
$offer = $st->fetch();
if (!$offer) { echo '<div class="alert alert-danger">Angebot nicht gefunden.</div>'; require __DIR__ . '/../../src/layout/footer.php'; exit; }

// Auswahllisten
$cs = $pdo->prepare('SELECT id, name FROM companies WHERE account_id = ? ORDER BY name');
$cs->execute([$account_id]); $companies = $cs->fetchAll();
$ps = $pdo->prepare('SELECT id, title FROM projects WHERE account_id = ? ORDER BY title');
$ps->execute([$account_id]); $projects = $ps->fetchAll();
$cts = $pdo->prepare('SELECT id, name FROM contacts WHERE account_id = ? ORDER BY name');
$cts->execute([$account_id]); $contacts = $cts->fetchAll();

// aktuell verknüpfte Aufgaben
$cur = $pdo->prepare('SELECT t.id, t.description, p.title AS project_title
  FROM offer_tasks ot
  JOIN tasks t ON t.id = ot.task_id AND t.account_id = ot.account_id
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE ot.offer_id = ? AND ot.account_id = ?
  ORDER BY p.title, t.id DESC');
$cur->execute([$id,$account_id]);
$current_tasks = $cur->fetchAll();

// alle Aufgaben zum Verknüpfen
$all = $pdo->prepare('SELECT t.id, t.description, p.title AS project_title
  FROM tasks t
  JOIN projects p ON p.id = t.project_id AND p.account_id = t.account_id
  WHERE t.account_id = ?
  ORDER BY p.title, t.id DESC
  LIMIT 1000');
$all->execute([$account_id]);
$all_tasks = $all->fetchAll();

$ok = $err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $company_id = (int)($_POST['company_id'] ?? 0);
  $contact_id = isset($_POST['contact_id']) && $_POST['contact_id'] !== '' ? (int)$_POST['contact_id'] : null;
  $project_id = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
  $hourly_rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
  $status = $_POST['status'] ?? 'offen';
  $sel_tasks = array_map('intval', $_POST['tasks'] ?? []);

  if ($company_id) {
    $upd = $pdo->prepare('UPDATE offers SET company_id=?, contact_id=?, project_id=?, hourly_rate=?, status=? WHERE id=? AND account_id=?');
    $upd->execute([$company_id,$contact_id,$project_id,$hourly_rate,$status,$id,$account_id]);

    // Sync offer_tasks
    // 1) aktuelle IDs laden
    $ids = $pdo->prepare('SELECT task_id FROM offer_tasks WHERE offer_id = ? AND account_id = ?');
    $ids->execute([$id,$account_id]);
    $current = array_map('intval', array_column($ids->fetchAll(), 'task_id'));
    $to_add = array_diff($sel_tasks, $current);
    $to_del = array_diff($current, $sel_tasks);

    if ($to_add) {
      $ins = $pdo->prepare('INSERT IGNORE INTO offer_tasks(offer_id, task_id, account_id) VALUES(?,?,?)');
      foreach ($to_add as $tid) { $ins->execute([$id,$tid,$account_id]); }
    }
    if ($to_del) {
      $in = implode(',', array_fill(0, count($to_del), '?'));
      $del = $pdo->prepare("DELETE FROM offer_tasks WHERE offer_id = ? AND account_id = ? AND task_id IN ($in)");
      $del->execute(array_merge([$id,$account_id], array_values($to_del)));
    }

    $ok = 'Gespeichert.';
    // reload
    $st->execute([$id,$account_id]); $offer = $st->fetch();
    $cur->execute([$id,$account_id]); $current_tasks = $cur->fetchAll();
  } else {
    $err = 'Firma ist erforderlich.';
  }
}
?>
<div class="row">
  <div class="col-md-7">
    <h3>Angebot bearbeiten</h3>
    <?php if ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
    <form method="post">
      <?=csrf_field()?>
      <div class="mb-3">
        <label class="form-label">Firma</label>
        <select name="company_id" class="form-select" required>
          <?php foreach ($companies as $c): ?>
            <option value="<?=$c['id']?>" <?=$c['id']==$offer['company_id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Ansprechpartner</label>
        <select name="contact_id" class="form-select">
          <option value="">(keiner)</option>
          <?php foreach ($contacts as $c): ?>
            <option value="<?=$c['id']?>" <?=$offer['contact_id']==$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Projekt</label>
        <select name="project_id" class="form-select">
          <option value="">(kein Projekt)</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>" <?=$offer['project_id']==$p['id']?'selected':''?>><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Stundensatz (€)</label>
          <input type="number" step="0.01" name="hourly_rate" class="form-control" value="<?=h($offer['hourly_rate'] ?? '')?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="offen" <?=$offer['status']==='offen'?'selected':''?>>offen</option>
            <option value="angenommen" <?=$offer['status']==='angenommen'?'selected':''?>>angenommen</option>
            <option value="abgelehnt" <?=$offer['status']==='abgelehnt'?'selected':''?>>abgelehnt</option>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Aufgaben zuordnen</label>
        <select name="tasks[]" class="form-select" multiple size="10">
          <?php foreach ($all_tasks as $t): ?>
            <?php $sel = in_array($t['id'], array_column($current_tasks,'id')) ? 'selected' : ''; ?>
            <option value="<?=$t['id']?>" <?=$sel?>><?=h($t['project_title'])?> — <?=h(mb_strimwidth($t['description'],0,60,'…'))?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Mehrfachauswahl mit Strg/Cmd.</div>
      </div>
      <button class="btn btn-primary">Speichern</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($return_to)) ?>">Abbrechen</a>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../src/layout/footer.php'; ?>
