## Step 1
```php
<?php

namespace App\Events;

use Doppar\Airbend\Support\Attributes\Broadcast;
use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;

#[Broadcast(channels: 'notifications')]
class NotificationCreated extends BaseBroadcastEvent
{
    public function __construct(
        public string $title,
        public string $message,
        public string $type = 'info'
    ) {}
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
use App\Events\NotificationCreated;

class SocketController extends Controller
{
    #[Route(uri: 'socket')]
    public function socketServer()
    {
        Broadcast::channel('notifications', new NotificationCreated('System Update', 'New features!', 'success'));

        return view('socket');
    }
}
```

## Step 5
```html
<!DOCTYPE html>
<html>

<head>
    <title>WebSocket Test</title>
</head>

<body>
    <h1>WebSocket Connection Test</h1>
    <div id="status">Connecting...</div>
    <div id="messages"></div>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="[[ enqueue('vendor/airbend/assets/js/doppar.js') ]]"></script>
    <script>
        const doppar = new Doppar({
            host: 'ws://127.0.0.1:6001'
        });

        doppar.channel('notifications')
            .listen('NotificationCreated', (data) => {
                console.log('New notification:', data);
            });
    </script>
</body>

</html>
```

## Step 6
```bash
php pool server:start
php pool websocket:start
```


