<?php
require_once __DIR__ . '/../bootstrap.php';
$user = auth_user();
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
        <?php endif; ?>
      </ul>
      <div class="d-flex">
        <?php if ($user): ?>
          <a class="btn btn-outline-success me-2" href="<?=url('/tasks/new.php')?>">Neue Aufgabe</a>
          <?php
            $running = get_running_time($pdo, (int)$user['account_id'], (int)$user['id']);
          ?>
          <?php if ($running): ?>
            <a class="btn btn-warning me-2" href="<?=url('/times/stop.php')?>">Timer stoppen</a>
          <?php else: ?>
            <a class="btn btn-outline-success me-2" href="<?=url('/times/start.php')?>">Timer starten</a>
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
<main class="container py-4">
