<?php

use Doppar\Airbend\Controllers\BroadcastAuthController;

if(!config('airbend.authorize.enabled')){
    return;
}else{
    $router = app('route');

    // ======================================
    // Load the Airbend Route
    // ======================================
    $router->post(config('airbend.authorize.endpoint'), [BroadcastAuthController::class, 'authenticate'])
        ->middleware(config('airbend.authorize.middleware'));
}

