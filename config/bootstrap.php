<?php

declare(strict_types=1);

use App\View;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$config = require __DIR__ . '/app.php';
$view = new View(dirname(__DIR__) . '/templates', dirname(__DIR__) . '/var/smarty');
$view->assign('appName', $config['name']);
$stylesheetVersion = substr(hash_file('sha256', dirname(__DIR__) . '/public/assets/css/app.css'), 0, 12);
$view->assign('stylesheetUrl', '/assets/css/app.css?v=' . $stylesheetVersion);

return ['config' => $config, 'view' => $view];
