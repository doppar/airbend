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
    | Supported: "redis", "null"
    |
    */

    'default' => env('BROADCAST_DRIVER', 'redis'),

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
        'redis' => [
            'connection' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
            'prefix' => env('REDIS_PREFIX', ''),
            'options' => [
                'parameters' => [
                    'password' => env('REDIS_PASSWORD') ?: null,
                    'database' => env('REDIS_DB') ? (int) env('REDIS_DB') : 0,
                ],
            ],
            'redis_poll_interval' => (float) env('REDIS_POLL_INTERVAL', 0.1),
            'broadcast_channel' => env('WEBSOCKET_CHANNEL', 'doppar-broadcast'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the behavior of the Workerman WebSocket server.
    | You can adjust them based on your application's performance needs.
    |
    | max_connections:
    |   The maximum number of simultaneous WebSocket clients that the server
    |   can handle. Increase this value if your application expects more users.
    |
    | connection_timeout:
    |   The number of seconds a connection can remain idle before Workerman
    |   automatically closes it. This helps free resources from inactive clients.
    |
    | heartbeat_interval:
    |   The interval (in seconds) at which Workerman will send automatic
    |   heartbeat (ping) messages to connected clients to ensure that they
    |   are still active and to keep the connection alive.
    |
    */

    'websocket' => [
        'max_connections' => (int) env('WEBSOCKET_MAX_CONNECTIONS', 1000),
        'connection_timeout' => (int) env('WEBSOCKET_CONNECTION_TIMEOUT', 180),
        'heartbeat_interval' => (int) env('WEBSOCKET_HEARTBEAT_INTERVAL', 30),
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
        'app_key' => env('APP_KEY', 'doppar-app-key'),
        'app_secret' => env('WEBSOCKET_APP_SECRET', 'doppar-app-secret'),
        'endpoint' => '/broadcasting/auth',
        'middleware' => ['auth'],
        'enabled' => true
    ]
];
