# Airbend

Real-time WebSocket broadcasting for Doppar framework.

## Requirements

Check that your system has the required PHP extensions:

```bash
php -m | grep -E "(sockets|pcntl|posix)"
```

## Quick Setup

1. **Create a broadcast event** in `app/Events/NotificationCreated.php`:

```php
<?php

namespace App\Events;

use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;

class NotificationCreated extends BaseBroadcastEvent
{
    public function __construct(
        public readonly array $notification,
        public readonly ?int $userId = null
    ) {}

    public function broadcastOn(): array
    {
        return $this->userId 
            ? ["user.{$this->userId}.notifications", 'notifications']
            : ['notifications'];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification['id'],
            'message' => $this->notification['message'],
            'created_at' => $this->notification['created_at'],
        ];
    }

    public function shouldBroadcast(): bool
    {
        return !empty($this->notification['message']);
    }
}
```

2. **Register the service provider** in `config/app.php`:

```php
'providers' => [
    // ... other providers
    Doppar\Airbend\AirbendServiceProvider::class,
],
```

3. **Publish the configuration**:

```bash
php pool vendor:publish --provider="Doppar\Airbend\AirbendServiceProvider"
```

4. **Create a controller** in `app/Http/Controllers/NotificationController.php`:
```php
<?php

namespace App\Http\Controllers;

use Phaseolies\Utilities\Attributes\Route;
use Doppar\Airbend\Support\Facades\Broadcast;
use App\Events\NotificationCreated;

class NotificationController extends Controller
{
    #[Route(uri: 'notifications')]
    public function create()
    {
        $notification = [
            'id' => 1,
            'message' => 'Welcome to Airbend!',
            'created_at' => now()->toISOString(),
        ];

        // Simple broadcast
        Broadcast::channel('notifications', new NotificationCreated($notification));

        // Or use the helper function
        broadcast('notifications', new NotificationCreated($notification));

        return response()->json(['status' => 'sent']);
    }
}
```

5. **Create an HTML client** (example):

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
        const channel = doppar.channel('notifications');

        channel.listen('notification.created', (data) => {
            console.log('Received notification:', data);
            
            addMessage(
                `${data.message} (ID: ${data.id})`,
                'info'
            );

            if (Notification.permission === 'granted') {
                new Notification('New Notification', {
                    body: data.message,
                    icon: '/icon.png'
                });
            }
        });

        // Listen for subscription success
        channel.listen('subscribed', () => {
            console.log('Successfully subscribed to notifications channel');
            addMessage('Subscribed to notifications channel', 'success');
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
            fetch('/notifications')
                .then(() => {
                    addMessage('Test notification sent', 'info');
                })
                .catch(err => {
                    addMessage('Failed to send notification: ' + err, 'error');
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

6. **Start the servers**:

```bash
# Start the web server
php pool server:start

# Start the WebSocket server
php pool websocket:start
```

Now open the HTML page in multiple browser tabs and click the "Send Test Broadcast" button to see real-time notifications across all tabs.
