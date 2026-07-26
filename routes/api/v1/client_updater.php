<?php

global $obRouter;

use App\Http\Response;
use App\Controller\Api;

$routeDefinition = [
    'middlewares' => [
        'api'
    ],
    function ($request) {
        return new Response(200, Api\ClientUpdater::getManifest($request), 'application/json');
    }
];

$obRouter->get('/api/v1/client_updater', $routeDefinition);
$obRouter->post('/api/v1/client_updater', $routeDefinition);
