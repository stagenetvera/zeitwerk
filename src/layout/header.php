<?php
require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../lib/flash.php';
$user = auth_user();

$return_to = $_SERVER['REQUEST_URI'] ?? url('/dashboard/index.php');

$__rt_running = null;
try {
  $rt = $pdo->prepare("
    SELECT t.id, t.task_id, t.started_at,
           ta.description AS task_desc,
           p.title        AS project_title
    FROM times t
    LEFT JOIN tasks ta ON ta.id = t.task_id AND ta.account_id = t.account_id
    LEFT JOIN projects p ON p.id = ta.project_id AND p.account_id = ta.account_id
    WHERE t.account_id = ? AND t.user_id = ? AND t.ended_at IS NULL
    ORDER BY t.id DESC
    LIMIT 1
  ");
  $rt->execute([(int)$user['account_id'], (int)$user['id']]);
  $__rt_running = $rt->fetch();
} catch (Throwable $e) {
  // optional: still bleiben; Anzeige einfach weglassen
}
?>
<!doctype html>
<html lang="de">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zeitwerk</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?=url('/index.php')?>">Zeitwerk</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($user): ?>
          <li class="nav-item"><a class="nav-link" href="<?=url('/dashboard/index.php')?>">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?=url('/companies/index.php')?>">Firmen</a></li>
        <li class="nav-item"><a class="nav-link" href="<?=url('/times/index.php')?>">Zeiten</a></li>
        <li class="nav-item"><a class="nav-link" href="<?=url('/offers/index.php')?>">Angebote</a></li>
        <li class="nav-item"><a class="nav-link" href="<?=url('/invoices/index.php')?>">Rechnungen</a></li>
        <li class="nav-item"><a class="nav-link" href="<?=url('/settings/index.php')?>">Einstellungen</a></li>

        <?php endif; ?>
      </ul>
      <div class="d-flex">
        <?php if ($user): ?>
          <a class="btn btn-outline-success me-2" href="<?=url('/tasks/new.php')?>">Neue Aufgabe</a>
          <?php
            $running = get_running_time($pdo, (int)$user['account_id'], (int)$user['id']);
          ?>
          <?php if ($running): ?>
            <form method="post" action="<?= url('/times/stop.php') ?>" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $__rt_running['id'] ?>">
              <?= return_to_hidden($return_to) ?>
              <button class="btn btn-warning me-2">Timer stoppen</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= url('/times/start.php') ?>" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="return_to" value="<?= h(url($return_to)) ?>">
              <button class="btn me-2 btn-success">Timer starten</button>
            </form>


          <?php endif; ?>
          <span class="navbar-text me-3">👤 <?=h($user['name'])?></span>
          <a class="btn btn-outline-light" href="<?=url('/logout.php')?>">Logout</a>
        <?php else: ?>
          <a class="btn btn-outline-light me-2" href="<?=url('/login.php')?>">Login</a>
          <a class="btn btn-success" href="<?=url('/register.php')?>">Registrieren</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<?php if (!empty($__rt_running)): ?>
            <?php
              $rt_id   = (int)$__rt_running['id'];
              $rt_task = (string)($__rt_running['task_desc'] ?? '');
              $rt_proj = (string)($__rt_running['project_title'] ?? '');
              // Startzeit als UNIX-Timestamp (für JS)
              try {
                $rt_started_ts = (new DateTimeImmutable($__rt_running['started_at']))->getTimestamp();
              } catch (Throwable $e) {
                $rt_started_ts = time();
              }
              $return_to = $_SERVER['REQUEST_URI'] ?? '/';
            ?>
            <div class="border-bottom bg-light">
              <div class="container py-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">⏱</span>
                    <span class="d-inline-block" style="width:70px">
                      <strong id="rt-elapsed" data-start="<?= (int)$rt_started_ts ?>">00:00:00</strong>
                    </span>
                    <span class="text-muted">
                      <?php if ($rt_proj): ?>
                        <span class="mx-1">•</span><?= h($rt_proj) ?>
                      <?php endif; ?>
                      <?php if ($rt_task): ?>
                        <span class="mx-1">•</span><?= h($rt_task) ?>
                      <?php endif; ?>
                    </span>
                  </div>

                </div>
              </div>
            </div>

            <script>
            (function(){
              var el = document.getElementById('rt-elapsed');
              if(!el) return;
              var started = parseInt(el.getAttribute('data-start'), 10) || Math.floor(Date.now()/1000);
              function pad(n){ return n<10 ? '0'+n : ''+n; }
              function tick(){
                var secs = Math.max(0, Math.floor(Date.now()/1000 - started));
                var h = Math.floor(secs/3600); secs = secs%3600;
                var m = Math.floor(secs/60); var s = secs%60;
                el.textContent = pad(h)+':'+pad(m)+':'+pad(s);
              }
              tick();
              setInterval(tick, 1000);
            })();
            </script>
          <?php endif; ?>
<main class="container py-4">
<?php flash_render_bootstrap(); ?>
