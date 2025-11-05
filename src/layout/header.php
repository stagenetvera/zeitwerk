<?php
 require_once __DIR__ . '/../bootstrap.php';

 require_once __DIR__ . '/../lib/flash.php';
 $user = auth_user();

//  $return_to = $_SERVER['REQUEST_URI'] ?? url('/dashboard/index.php');
 if ($user) {
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
}
?>
<!doctype html>
<html lang="de">
<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zeitwerk</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    /* kompakte, konsistente Icon-Buttons */
    .btn-icon{
      display:inline-flex; align-items:center; justify-content:center;
      width: 1.9rem; height: 1.9rem; padding: 0; line-height: 1;
    }
    .btn-icon i{ font-size: 1.05rem; line-height: 1; }
    /* Optional: gleiche Optik auch bei .btn-sm erzwingen */
    .btn.btn-sm.btn-icon{ width: 1.9rem; height: 1.9rem; }

    .drag-handle {
      cursor: grab;
      user-select: none;
      /* opacity: .6; */
    }
    tr.dragging {
      opacity: .6;
    }
    tr.placeholder td {
      padding: 0 !important;
    }
    tr.placeholder td::after {
      content: "";
      display:block;
      height: 12px;
      border: 2px dashed #bbb;
      border-radius: 6px;
      margin: 4px 0;
    }

    /* Für FLIP – sanfter Move */
    tbody#dashTaskBody tr {
      transition: transform 150ms ease;
    }

  .flash-stack{
    position: fixed;
    top: 16px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 1100; /* over navs/dropdowns */
    width: min(720px, calc(100vw - 32px));
    pointer-events: none; /* clicks pass through stack except alerts */
  }
  .flash-stack .alert{
    pointer-events: auto;
    margin: 0 0 .5rem 0;
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.15);
    border-radius: .5rem;
    opacity: 1;
    transform: translateY(0);
    transition: opacity .35s ease, transform .35s ease;
  }
  .flash-stack .alert.flash-enter{ opacity: 0; transform: translateY(-6px); }
  .flash-stack .alert.flash-leave{ opacity: 0; transform: translateY(-6px); }

  .extra-row .form-label { margin-bottom: .25rem; }
  @media (min-width: 768px) {
    .extra-row .col-md-2 .form-label { white-space: nowrap; }
  }
  /* Toggle-Buttons kompakt & bündig */
  .input-group .btn.mode-btn { min-width: 2.6rem; }
  /* Visuelle Hilfe unter Preisfeld entfällt überall */
  .rate-help { display: none !important; }
  .mode-btn i { pointer-events: none; } /* Icon klickt wie der Button */

  /* Chrome, Edge, Safari, Opera */
  .no-spin::-webkit-outer-spin-button,
  .no-spin::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
  }

  /* Firefox (und generisch) */
  .no-spin {
    -moz-appearance: textfield;
    appearance: textfield;
  }

  .no-pointer { pointer-events:none; opacity:.7; }

  </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo url('/index.php') ?>">Zeitwerk</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($user): ?>
          <li class="nav-item"><a class="nav-link" href="<?php echo url('/dashboard/index.php') ?>">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?php echo url('/companies/index.php') ?>">Firmen</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo url('/times/index.php') ?>">Zeiten</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo url('/offers/index.php') ?>">Angebote</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo url('/invoices/index.php') ?>">Rechnungen</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo url('/settings/index.php') ?>">Einstellungen</a></li>

        <?php endif; ?>
      </ul>
      <div class="d-flex">
        <?php if ($user): ?>

          <form method="post" action="<?= url('/tasks/new.php') ?>" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= h($_SERVER["REQUEST_URI"]) ?>">
            <button class="btn btn-outline-success me-2">Neue Aufgabe</button>
          </form>

          <?php
           $running = get_running_time($pdo, (int)$user['account_id'], (int)$user['id']);
          ?>
          <?php if ($running): ?>
            <form method="post" action="<?php echo url('/times/stop.php') ?>" class="d-inline">
              <?php echo csrf_field() ?>
              <input type="hidden" name="id" value="<?php echo $__rt_running['id'] ?>">
              <input type="hidden" name="return_to" value="<?= h($_SERVER["REQUEST_URI"]) ?>">
              <button class="btn btn-warning me-2">Timer stoppen</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?php echo url('/times/start.php') ?>" class="d-inline">
              <?php echo csrf_field() ?>
              <input type="hidden" name="return_to" value="<?= h($_SERVER["REQUEST_URI"]) ?>">
              <button class="btn me-2 btn-success">Timer starten</button>
            </form>


          <?php endif; ?>
          <span class="navbar-text me-3">👤 <?php echo h($user['name']) ?></span>
          <a class="btn btn-outline-light" href="<?php echo url('/logout.php') ?>">Logout</a>
        <?php else: ?>
          <a class="btn btn-outline-light me-2" href="<?php echo url('/login.php') ?>">Login</a>
          <a class="btn btn-success" href="<?php echo url('/register.php') ?>">Registrieren</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<?php if (! empty($__rt_running)): ?>
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
            //  $return_to = $_SERVER['REQUEST_URI'] ?? '/';
            ?>
            <div class="border-bottom bg-light">
              <div class="container py-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">⏱</span>
                    <span class="d-inline-block" style="width:70px">
                      <strong id="rt-elapsed" data-start="<?php echo (int)$rt_started_ts ?>">00:00:00</strong>
                    </span>
                    <span class="text-muted">
                      <?php if ($rt_proj): ?>
                        <span class="mx-1">•</span><?php echo h($rt_proj) ?>
                      <?php endif; ?>
                      <?php if ($rt_task): ?>
                        <span class="mx-1">•</span><?php echo h($rt_task) ?>
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
