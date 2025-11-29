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


# Chat Test
## Controller
```php
<?php

namespace App\Http\Controllers;

use App\Events\NotificationCreatedEvent;
use Phaseolies\Utilities\Attributes\Route;
use Doppar\Airbend\Support\Facades\Broadcast;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Events\StockPriceUpdatedEvent;
use App\Events\UserJoinedRoomEvent;

class SocketController extends Controller
{
    #[Route(uri: 'socket/{clientId}')]
    public function socketServer(int $clientId)
    {
        // $data = (object) [
        //     'id' => 1,
        //     'type' => 'notify',
        //     'title' => 'Lorem ipsum dolor sit amet.',
        //     'message' => 'Lorem, ipsum dolor sit amet consectetur adipisicing elit. Sit, at',
        //     'icon_url' => null, // optional
        //     'action_url' => 'https://doppar.com/test_private_channel',
        //     'created_at' => now(),
        // ];

        // $userId = auth()->id() ?? null;

        // // Broadcast the notification
        // broadcast('private-user.' . $userId, new NotificationCreatedEvent($data, $userId));

        $user = auth()->user(); // get the authenticated user
        $room = (object) [
            'id' => 1,
            'name' => 'Test Room',
        ];

        // Build data similar to UserJoinedRoomEvent
        $user = auth()->user();
        $room = (object) [
            'id' => 1,
            'name' => 'Test Room',
        ];

        // Broadcast the event to a private channel (like UserJoinedRoomEvent)
        broadcast(new UserJoinedRoomEvent($user, $room));

        $userId = auth()->id();

        return view('socket', compact('userId'));
    }
}

<?php

namespace App\Http\Controllers;

use Phaseolies\Utilities\Attributes\Route;
use Phaseolies\Http\Request;
use App\Http\Controllers\Controller;
use App\Events\ChatMessageSentEvent;

class ChatController extends Controller
{
    #[Route(uri: 'chat/message', methods: ['POST'])]
    public function sendMessage(Request $request)
    {
        $request->sanitize([
            'room_id' => 'required|integer',
            'message' => 'required|string|max:1000'
        ]);

        $user = auth()->user();
        $roomId = $request->input('room_id');
        $messageText = $request->input('message');

        broadcast(new ChatMessageSentEvent($user, $roomId, $messageText));

        return response()->json(['status' => 'success']);
    }
}
```

## Event
```php
<?php

namespace App\Events;

use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;
use Doppar\Airbend\Support\Attributes\Broadcast;

class ChatMessageSentEvent extends BaseBroadcastEvent
{
    protected bool $toOthers = true;

    public function __construct(
        public $user,
        public $roomId,
        public $message
    ) {}

    public function broadcastOn(): array
    {
        return ["presence-room.{$this->roomId}"];
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar_url,
            ],
            'text' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
<?php

namespace App\Events;

use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;
// use Doppar\Airbend\Support\Attributes\Broadcast;

// #[Broadcast(channels: user_joined_room))]
class UserJoinedRoomEvent extends BaseBroadcastEvent
{
    public function __construct(
        public $user,
        public $room
    ) {}

    public function broadcastOn(): array
    {
        return ["presence-room.{$this->room->id}"];
    }

    public function broadcastAs(): string
    {
        return 'user.joined';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar_url,
            ],
            'text' => "{$this->user->name} joined the room",
            'joined_at' => now()->toIso8601String(),
        ];
    }
}
```

## Client
```html
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            height: 100vh;
            margin: 0;
        }

        .sidebar {
            width: 250px;
            background: #f4f4f4;
            border-right: 1px solid #ddd;
            padding: 10px;
            box-sizing: border-box;
        }

        .sidebar h2 {
            margin-top: 0;
        }

        #online-users {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .user-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .user-item img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            background: #ddd;
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-left: auto;
        }

        .status-indicator.online {
            background: green;
        }

        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        #chat-messages {
            flex: 1;
            padding: 10px;
            overflow-y: auto;
            border-bottom: 1px solid #ddd;
        }

        .chat-message {
            margin-bottom: 10px;
        }

        .chat-message .username {
            font-weight: bold;
            margin-right: 5px;
        }

        #typing-indicator {
            font-style: italic;
            color: #666;
            height: 20px;
            padding-left: 10px;
        }

        .chat-input {
            display: flex;
            padding: 10px;
        }

        .chat-input input {
            flex: 1;
            padding: 8px;
            font-size: 14px;
        }

        .chat-input button {
            padding: 8px 12px;
            margin-left: 5px;
        }

        #user-count {
            font-weight: bold;
            margin-top: 10px;
        }

        .system-message {
            font-style: italic;
            color: #999;
            margin-bottom: 5px;
        }
    </style>
</head>

<body>

    <div class="sidebar">
        <h2>Online Users (<span id="user-count">0</span>)</h2>
        <div id="online-users"></div>
    </div>

    <div class="chat-container">
        <div id="chat-messages"></div>
        <div id="typing-indicator"></div>
        <div class="chat-input">
            <input type="text" id="message-input" placeholder="Type a message...">
            <button id="send-btn">Send</button>
        </div>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="[[ enqueue('vendor/airbend/assets/js/doppar.js') ]]"></script>
    <script>
        const currentUserId = "[[ $userId ]]"
        const currentUser = {
            id: currentUserId,
            name: "[[ auth()->user()->name ]]",
            avatar: "[[ auth()->user()->avatar_url ]]"
        };

        const doppar = new Doppar({
            host: 'ws://127.0.0.1:6001',
            authHeaders: {
                'X-CSRF-TOKEN': "[[ csrf_token() ]]",
            },
        });

        const roomId = 1;
        const roomChannel = doppar.join(`room.${roomId}`);

        let onlineUsers = [];
        let canPlaySound = false;

        // Enable sounds after user interaction
        document.addEventListener('click', () => {
            canPlaySound = true;
        }, {
            once: true
        });

        // Debug connection
        doppar.on('connected', () => {
            console.log('WebSocket connected');
        });

        doppar.on('error', (error) => {
            console.error('WebSocket error:', error);
        });

        // Debug all events
        roomChannel.listen('*', (event, data) => {
            console.log('Event received:', event, data);
        });

        // Get current members
        roomChannel.here((members) => {
            console.log('Current members:', members);
            onlineUsers = members;
            renderUserList(onlineUsers);
        });

        // When someone joins
        roomChannel.joining((member) => {
            console.log('User joined:', member);
            if (!onlineUsers.find(u => u.user_id === member.user_id)) {
                onlineUsers.push(member);
            }
            renderUserList(onlineUsers);

            const userName = member.user_info?.name || 'Unknown User';
            showSystemMessage(`${userName} joined the room`);

            if (canPlaySound) {
                playJoinSound();
            }
        });

        // When someone leaves
        roomChannel.leaving((member) => {
            console.log('User left:', member);
            onlineUsers = onlineUsers.filter(u => u.user_id !== member.user_id);
            renderUserList(onlineUsers);

            const userName = member.user_info?.name || 'Unknown User';
            showSystemMessage(`${userName} left the room`);
        });

        // Listen for chat messages
        roomChannel.listen('ChatMessageSent', (message) => {
            console.log('Chat message received:', message);
            appendChatMessage(message);
        });

        // Listen for user join events
        roomChannel.listen('UserJoinedRoom', (message) => {
            console.log('User joined event:', message);
            showSystemMessage(`${message.user?.name || 'Someone'} joined the chat`);
        });

        // Typing indicator - CORRECT WHISPER USAGE
        let typingTimeout;
        let isTyping = false;
        const messageInput = document.getElementById('message-input');

        messageInput.addEventListener('input', () => {
            if (!isTyping) {
                isTyping = true;
                roomChannel.whisper('typing', {
                    user_id: currentUserId,
                    user_name: currentUser.name,
                    typing: true
                });
            }

            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                isTyping = false;
                roomChannel.whisper('typing', {
                    user_id: currentUserId,
                    user_name: currentUser.name,
                    typing: false
                });
            }, 1000);
        });

        roomChannel.listen('client-typing', (data) => {
            console.log('Typing event received:', data);
            if (data.user_id !== currentUserId) {
                showTypingIndicator(data.user_id, data.user_name, data.typing);
            }
        });

        // Render user list
        function renderUserList(users) {
            const userList = document.getElementById('online-users');
            userList.innerHTML = users.map(user => {
                const avatarUrl = user.user_info?.avatar || generateAvatar(user.user_info?.name || 'User');
                const userName = user.user_info?.name || 'Unknown User';

                return `
            <div class="user-item">
                <img src="${avatarUrl}" alt="${userName}">
                <span>${userName}</span>
                <span class="status-indicator online"></span>
            </div>
        `;
            }).join('');

            document.getElementById('user-count').textContent = users.length;
        }

        // Generate avatar with initials
        function generateAvatar(name) {
            const initials = name.split(' ').map(n => n[0]).join('').toUpperCase();
            const colors = ['#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#98D8C8'];
            const color = colors[initials.charCodeAt(0) % colors.length];

            return `data:image/svg+xml;base64,${btoa(`
        <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="40" height="40" fill="${color}"/>
            <text x="20" y="20" font-family="Arial" font-size="14" fill="#FFFFFF" text-anchor="middle" dy=".3em">${initials}</text>
        </svg>
    `)}`;
        }

        // Append chat message
        function appendChatMessage(message) {
            const chat = document.getElementById('chat-messages');
            const msg = document.createElement('div');
            msg.classList.add('chat-message');

            const userName = message.user?.name || 'Unknown User';
            const messageText = message.text || message.message || '';

            msg.innerHTML = `<span class="username">${userName}:</span> ${messageText}`;
            chat.appendChild(msg);
            chat.scrollTop = chat.scrollHeight;
        }

        // Show system messages
        function showSystemMessage(text) {
            const chat = document.getElementById('chat-messages');
            const msg = document.createElement('div');
            msg.classList.add('system-message');
            msg.textContent = text;
            chat.appendChild(msg);
            chat.scrollTop = chat.scrollHeight;
        }

        // Show typing indicator
        function showTypingIndicator(userId, userName, isTyping) {
            const indicator = document.getElementById('typing-indicator');
            if (isTyping) {
                indicator.textContent = `${userName} is typing...`;
            } else {
                indicator.textContent = '';
            }
        }

        // Play join sound
        function playJoinSound() {
            if (!canPlaySound) return;

            try {
                const audioContext = new(window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);

                oscillator.frequency.value = 800;
                oscillator.type = 'sine';

                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.2);

                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.2);
            } catch (error) {
                console.log('Audio not supported');
            }
        }

        // Send message
        document.getElementById('send-btn').addEventListener('click', sendMessage);
        messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });

        async function sendMessage() {
            const text = messageInput.value.trim();
            if (!text) return;

            try {
                // Show message immediately for current user
                const tempMessage = {
                    user: currentUser,
                    text: text
                };
                appendChatMessage(tempMessage);

                // Send to backend for broadcasting to all users
                const response = await fetch('/chat/message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': "[[ csrf_token() ]]",
                        'X-Socket-ID': doppar.getSocketId()
                    },
                    body: JSON.stringify({
                        room_id: roomId,
                        message: text
                    })
                });

                if (!response.ok) {
                    throw new Error('Failed to send message');
                }

                messageInput.value = '';

            } catch (error) {
                console.error('Error sending message:', error);
                showSystemMessage('Failed to send message. Please try again.');
            }
        }

        // Test whispering with a simple button
        document.addEventListener('DOMContentLoaded', function() {
            const sendBtn = document.getElementById('send-btn');

            // Add right-click to test whisper
            sendBtn.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                roomChannel.whisper('typing', {
                    user_id: currentUserId,
                    user_name: currentUser.name,
                    typing: true,
                    test: true
                });
                console.log('Test whisper sent');
            });
        });
    </script>
</body>

</html>
```

## Authorize channel access
`routes/channels.php`
```php
<?php

/**
 * Broadcast Channel Authorization
 *
 * Define channel authorization logic here.
 * These closures will be called when a user attempts to join a private or presence channel.
 *
 */

use Doppar\Airbend\Broadcasting\Channel;
use Phaseolies\Http\Request;

/*
|--------------------------------------------------------------------------
| Private User Channels
|--------------------------------------------------------------------------
|
| Private channels for individual users
| Format: private-user.{userId}
|
*/

Channel::authorize('private-user.{userId}', function (Request $request, int $userId) {
    // User can only join their own channel
    return $request->user()?->id === (int) $userId;
});

/*
|--------------------------------------------------------------------------
| Presence Chat Room Channels
|--------------------------------------------------------------------------
|
| Presence channels for chat rooms with member tracking
| Format: presence-room.{roomId}
|
*/

Channel::authorize('presence-room.{roomId}', function (Request $request, int $roomId) {
    $user = $request->user();

    if (!$user) {
        return false;
    }

    // $room = ChatRoom::find($roomId);

    // // Check if user can access room
    // if (!$room || !$room->canUserAccess($user)) {
    //     return false;
    // }

    return [
        'user_id' => $user->id,
        'user_info' => [
            'name' => $user->name,
            'email' => $user->email
        ],
    ];
});

/*
|--------------------------------------------------------------------------
| Private Team Channels
|--------------------------------------------------------------------------
|
| Private channels for team communication
| Format: private-team.{teamId}
|
*/

Channel::authorize('private-team.{teamId}', function (Request $request, int $teamId) {
    $user = $request->user();

    if (!$user) {
        return false;
    }

    // Check if user belongs to the team
    // return $user->teams()->where('id', $teamId)->exists();
});
```