<?php

declare(strict_types=1);

use App\View;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$config = require __DIR__ . '/app.php';
$view = new View(dirname(__DIR__) . '/templates', dirname(__DIR__) . '/var/smarty');
$view->assign('appName', $config['name']);

return ['config' => $config, 'view' => $view];
