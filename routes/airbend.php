<?php

use Doppar\Airbend\Controllers\BroadcastAuthController;

$router = app('route');

// ======================================
// Load the Airbend Route
// ======================================

$router->post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate'])
    ->middleware(config('airbend.authorize.middleware'));