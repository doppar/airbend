## Dependency 
```bash
php -m | grep -E 'pcntl|posix|event'
```

Check if the following extensions are enabled:
- pcntl
- posix
- event [Optional for better performance]

## Step 1
```php
<?php

namespace App\Events;

use Doppar\Airbend\Support\Attributes\Broadcast;
use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;

// #[Broadcast(channels: 'notifications', as: 'system_update_listener')]
class SystemUpdate extends BaseBroadcastEvent
{
    public function __construct(
        public string $message,
        public string $level = 'info'
    ) {}

    public function broadcastOn(): array
    {
        return ['system_update'];
    }

    public function broadcastAs(): string
    {
        return 'system_update_listener';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'level' => $this->level,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

## Setp 2
```php
"providers" => [
    \Doppar\Airbend\AirbendServiceProvider::class,
],
```

### Step 3
```bash
php pool vendor:publish --provider="Doppar\Airbend\AirbendServiceProvider"
```

### Step 4
```php
<?php

namespace App\Http\Controllers;

use Phaseolies\Utilities\Attributes\Route;
use Doppar\Airbend\Support\Facades\Broadcast;
use App\Http\Controllers\Controller;
use App\Events\SystemUpdate;
use App\Events\NotificationCreated;

class SocketController extends Controller
{
    #[Route(uri: 'socket/{clientId}')]
    public function socketServer(int $clientId)
    {
        Broadcast::channel('system_update', new SystemUpdate('Database backup completed'));
        // broadcast(new SystemUpdate('System maintenance scheduled', 'warning'));

        return view('socket');
    }
}
```

## Step 5
```html
<!DOCTYPE html>
<html>

<head>
    <title>Doppar Broadcasting Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
        }

        .status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
        }

        .connected {
            background: #d4edda;
            color: #155724;
        }

        .disconnected {
            background: #f8d7da;
            color: #721c24;
        }

        .message {
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #007bff;
            background: #f8f9fa;
        }

        .message.info {
            border-color: #007bff;
        }

        .message.success {
            border-color: #28a745;
        }

        .message.warning {
            border-color: #ffc107;
        }

        .message.error {
            border-color: #dc3545;
        }

        button {
            padding: 10px 20px;
            margin: 5px;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <h1>Doppar Broadcasting Test</h1>

    <div id="status" class="status disconnected">
        Status: Disconnected
    </div>

    <div id="socket-id">
        Socket ID: <span id="socket-id-value">Not connected</span>
    </div>

    <div>
        <button onclick="testBroadcast()">Send Test Broadcast</button>
        <button onclick="reconnect()">Reconnect</button>
        <button onclick="clearMessages()">Clear Messages</button>
    </div>

    <h2>Received Messages:</h2>
    <div id="messages"></div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="[[ enqueue('vendor/airbend/assets/js/doppar.js') ]]"></script>
    <script>
        // ====================================================================
        // Initialize Doppar Connection
        // ====================================================================
        const doppar = new Doppar({
            host: 'ws://127.0.0.1:6001',
            authEndpoint: '/broadcasting/auth',
            encrypted: false,
            reconnect: true,
            reconnectAttempts: 5,
            reconnectInterval: 3000,
        });

        // ====================================================================
        // Connection Events
        // ====================================================================
        doppar.on('connected', () => {
            console.log('[App] Connected to WebSocket server');
            updateStatus('connected', 'Connected');
            document.getElementById('socket-id-value').textContent =
                doppar.getSocketId();
        });

        doppar.on('disconnected', () => {
            console.log('[App] Disconnected from server');
            updateStatus('disconnected', 'Disconnected');
            document.getElementById('socket-id-value').textContent =
                'Not connected';
        });

        doppar.on('error', (error) => {
            console.error('[App] WebSocket error:', error);
            addMessage('Connection error: ' + error, 'error');
        });

        // ====================================================================
        // Subscribe to Channel and Listen for Events
        // ====================================================================
        const channel = doppar.channel('system_update');

        // The event name here must match broadcastAs() in event
        channel.listen('system_update_listener', (data) => {
            console.log('[App] Received system update:', data);

            // Display the message
            addMessage(
                `${data.message} (${data.timestamp})`,
                data.level || 'info'
            );

            if (Notification.permission === 'granted') {
                new Notification('System Update', {
                    body: data.message,
                    icon: '/icon.png'
                });
            }
        });

        // Listen for subscription success
        channel.listen('subscribed', () => {
            console.log('[App] Successfully subscribed to system_update channel');
            addMessage('Subscribed to system_update channel', 'success');
        });

        function updateStatus(status, text) {
            const statusEl = document.getElementById('status');
            statusEl.className = 'status ' + status;
            statusEl.textContent = 'Status: ' + text;
        }

        function addMessage(text, level = 'info') {
            const messagesDiv = document.getElementById('messages');
            const messageEl = document.createElement('div');
            messageEl.className = 'message ' + level;
            messageEl.innerHTML = `
                <strong>${level.toUpperCase()}</strong>: ${text}
                <br><small>${new Date().toLocaleTimeString()}</small>
            `;
            messagesDiv.insertBefore(messageEl, messagesDiv.firstChild);
        }

        function testBroadcast() {
            // Trigger a server-side broadcast
            fetch('/socket/1')
                .then(() => {
                    addMessage('Test broadcast triggered', 'info');
                })
                .catch(err => {
                    addMessage('Failed to trigger broadcast: ' + err, 'error');
                });
        }

        function reconnect() {
            doppar.disconnect();
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        function clearMessages() {
            document.getElementById('messages').innerHTML = '';
        }

        // ====================================================================
        // Request notification permission
        // ====================================================================
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    </script>
</body>

</html>
```

## Step 6
```bash
php pool server:start
php pool websocket:start
```

Now open 2 tab in your browser, trigger `testBroadcast` button and check update in both browser tab.
