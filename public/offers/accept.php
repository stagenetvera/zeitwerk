<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
echo '<h3>Angebot annehmen</h3><div class="alert alert-info">MVP: Workflow folgt (Status -> Aufgaben auf "offen").</div>';
require __DIR__ . '/../../src/layout/footer.php';
