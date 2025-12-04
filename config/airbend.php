<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcasting Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "websocket", "null"
    |
    */

    'default' => env('BROADCAST_DRIVER', 'websocket'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Each connection
    | option is configured with a "driver" option to identify how the driver
    | will broadcast the message.
    |
    */

    'connections' => [
        'websocket' => [
            'host' => env('WEBSOCKET_HOST', '127.0.0.1'),
            'port' => (int) env('WEBSOCKET_PORT', 6001),
            'ssl' => (bool) env('WEBSOCKET_SSL', false),
            'max_connections' => (int) env('WEBSOCKET_MAX_CONNECTIONS', 1000),
            'connection_timeout' => (int) env('WEBSOCKET_CONNECTION_TIMEOUT', 180),
            'heartbeat_interval' => (int) env('WEBSOCKET_HEARTBEAT_INTERVAL', 30),
            'redis_poll_interval' => (float) env('WEBSOCKET_REDIS_POLL_INTERVAL', 0.1),
            'broadcast_channel' => env('WEBSOCKET_CHANNEL', 'doppar-broadcast'),
            'redis' => [
                'connection' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
                'prefix' => env('REDIS_PREFIX', ''),
                'options' => [
                    'parameters' => [
                        'password' => env('REDIS_PASSWORD') ?: null,
                        'database' => env('REDIS_DB') ? (int)env('REDIS_DB') : 0,
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Authorization
    |--------------------------------------------------------------------------
    |
    | Define channel authorization callbacks. These callbacks determine
    | if a user is authorized to join private or presence channels.
    |
    */

    'authorize' => [
        'app_key' => env('WEBSOCKET_APP_KEY', 'doppar-app-key'),
        'app_secret' => env('WEBSOCKET_APP_SECRET', 'doppar-app-secret'),
        'endpoint' => '/broadcasting/auth',
        'middleware' => ['auth'],
        'enabled' => true
    ],

];
