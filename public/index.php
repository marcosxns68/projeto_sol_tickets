<?php

use App\Support\DeploymentMigration;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require_once __DIR__.'/deployment-bootstrap.php';

DeploymentBootstrap::apply(
    __DIR__.'/../storage/app/private/deploy-release.zip',
    dirname(__DIR__)
);

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

DeploymentMigration::run(
    $app,
    __DIR__.'/../storage/framework/deploy-migrate',
    [__DIR__.'/release.zip', __DIR__.'/deploy.php']
);

$app->handleRequest(Request::capture());
