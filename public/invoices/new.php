<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
echo '<h3>Neue Rechnung</h3><div class="alert alert-info">MVP: Auswahl offener, fakturierbarer Zeiten je Projekt folgt.</div>';
require __DIR__ . '/../../src/layout/footer.php';
