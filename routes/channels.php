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
});
