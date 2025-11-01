<?php
require __DIR__ . '/../src/bootstrap.php';
logout();
header('Location: '.APP_BASE_URL);
exit;
