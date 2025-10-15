<?php
require __DIR__ . '/../../src/layout/header.php';
require_login();
header('Content-Type: application/xml; charset=utf-8');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<invoice><todo>Implement XML-Export</todo></invoice>";
exit;
