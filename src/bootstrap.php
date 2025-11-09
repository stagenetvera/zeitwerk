<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/return_to.php';

define('APP_BASE_URL', rtrim($config['app']['base_url'] ?? '', '/'));

if (!isset($config['app']['timezone'])) {
    $config['app']['timezone'] = 'Europe/Berlin';
}
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

require __DIR__ . '/../vendor/autoload.php';

session_start();
$pdo = db_connect($config['db']);
?>
