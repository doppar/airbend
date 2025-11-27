<?php

use Doppar\Airbend\Controllers\BroadcastAuthController;

$router = app('route');

// ======================================
// Load the Airbend Route
// ======================================

$router->get('broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);