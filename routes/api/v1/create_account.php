<?php

use App\Http\Response;
use App\Controller\Api\CreateAccount;

$obRouter->post('/api/v1/create_account', [
    'middlewares' => [
        'api'
    ],
    function($request){
        return new Response(200, CreateAccount::handle($request), 'application/json');
    }
]);
