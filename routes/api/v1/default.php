<?php

use App\Http\Response;
use App\Controller\Api;

// Rota raiz da API v1
$obRouter->get('/api/v1', [
    'middlewares' => [
        'api'
    ],
    function($request){
        return new Response(200, Api\Api::getDetails($request), 'application/json');
    }
]);
$obRouter->get('/api/v1/status', [
    'middlewares' => [
        'api'
    ],
    function(){
        $response = new Response(200, Api\Api::getStatus(), 'application/json');
        $response->addHeader('Cache-Control', 'public, max-age=30');
        return $response;
    }
]);
