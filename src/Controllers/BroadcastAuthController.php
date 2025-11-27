<?php

namespace Doppar\Airbend\Controllers;

use Phaseolies\Utilities\Attributes\Middleware;
use Phaseolies\Support\Facades\Log;
use Phaseolies\Http\Request;
use Doppar\Airbend\Support\Facades\Broadcast;
use Doppar\Airbend\Broadcasting\Channel;
use App\Http\Controllers\Controller;
use Phaseolies\Http\Response\JsonResponse;

#[Middleware('auth')]
class BroadcastAuthController extends Controller
{
    /**
     * Authenticate the request for channel access
     *
     * @param Request $request
     * @return \Phaseolies\Http\Response\JsonResponse
     */
    public function authenticate(Request $request): JsonResponse
    {
        $socketId = $request->input('socket_id');
        $channel = $request->input('channel_name');

        // Validate required parameters
        if (!$socketId || !$channel) {
            return response()->json([
                'error' => 'Missing required parameters',
                'message' => 'Both socket_id and channel_name are required'
            ], 400);
        }

        // Validate socket ID format
        if (!$this->isValidSocketId($socketId)) {
            return response()->json([
                'error' => 'Invalid socket ID format'
            ], 400);
        }

        // Validate channel name format
        if (!$this->isValidChannelName($channel)) {
            return response()->json([
                'error' => 'Invalid channel name format'
            ], 400);
        }

        try {
            // Use Channel helper for authorization
            $authResult = Channel::authorizeChannel($request, $channel);

            // If authorization failed
            if ($authResult === false) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'You do not have permission to access this channel'
                ], 403);
            }

            // For private channels, authResult is true
            // For presence channels, authResult is an array with user data
            $userData = is_array($authResult) ? $authResult : null;

            // Check if this is actually a presence channel but no user data was provided
            if (str_starts_with($channel, 'presence-') && !is_array($authResult)) {
                return response()->json([
                    'error' => 'Invalid presence channel configuration',
                    'message' => 'Presence channels must return user data'
                ], 500);
            }

            // Generate authentication signature
            $auth = Broadcast::driver()->authenticate($socketId, $channel, $userData);

            return response()->json($auth);
        } catch (\Exception $e) {
            Log::error('Broadcast authentication error', [
                'channel' => $channel,
                'socket_id' => $socketId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Authentication failed',
                'message' => 'An error occurred during authentication'
            ], 500);
        }
    }

    /**
     * Validate socket ID format
     *
     * @param string $socketId
     * @return bool
     */
    protected function isValidSocketId(string $socketId): bool
    {
        // Socket ID should be in format: xxx.yyyy
        return (bool) preg_match('/^[\w\-\.]+$/', $socketId);
    }

    /**
     * Validate channel name format
     *
     * @param string $channel
     * @return bool
     */
    protected function isValidChannelName(string $channel): bool
    {
        // Channel name should only contain alphanumeric, dash, underscore, and dot
        return (bool) preg_match('/^[a-zA-Z0-9\-\_\.]+$/', $channel);
    }

    /**
     * Legacy authorization method (kept for backward compatibility)
     * You can also define authorization logic directly here if you prefer
     * not to use the routes/channels.php file
     *
     * @param Request $request
     * @param string $channel
     * @return bool|array
     */
    protected function authorizeChannel(Request $request, string $channel): bool|array
    {
        $user = $request->user();

        // Public channels are always accessible
        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            return true;
        }

        // Ensure user is authenticated for private/presence channels
        if (!$user) {
            return false;
        }

        // Private user channel: private-user.{id}
        if (preg_match('/^private-user\.(\d+)$/', $channel, $matches)) {
            return $user->id == $matches[1];
        }

        // Presence channel: presence-room.{id}
        if (preg_match('/^presence-room\.(\d+)$/', $channel, $matches)) {
            // Check if user has access to this room
            // Return user data for presence channel
            return [
                'user_id' => $user->id,
                'user_info' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar ?? null,
                ],
            ];
        }

        // Team channels: private-team.{id}
        if (preg_match('/^private-team\.(\d+)$/', $channel, $matches)) {
            $teamId = $matches[1];
            // Check if user belongs to team
            return $user->teams()->where('id', $teamId)->exists();
        }

        // Admin channels
        if (str_starts_with($channel, 'private-admin')) {
            return $user->isAdmin();
        }

        // Default: deny access
        return false;
    }

    /**
     * Get user data for presence channels
     *
     * @param Request $request
     * @return array
     */
    protected function getUserData(Request $request): array
    {
        $user = $request->user();

        return [
            'user_id' => $user->id,
            'user_info' => [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ?? null,
                'status' => $user->status ?? 'online',
            ],
        ];
    }
}
