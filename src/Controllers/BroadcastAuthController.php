<?php

namespace Doppar\Airbend\Controllers;

use Phaseolies\Support\Facades\Auth;
use Phaseolies\Http\Response\JsonResponse;
use Phaseolies\Http\Request;
use Doppar\Airbend\Support\Facades\Broadcast;
use Doppar\Airbend\Broadcasting\Channel;
use App\Http\Controllers\Controller;

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
        if (!Auth::check()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'You must be logged in to access this channel'
            ], 401);
        }

        $socketId = $request->input('socket_id');
        $channel = $request->input('channel_name');

        if (!$socketId || !$channel) {
            return response()->json([
                'error' => 'Missing required parameters',
                'message' => 'Both socket_id and channel_name are required'
            ], 400);
        }

        if (!$this->isValidSocketId($socketId)) {
            return response()->json([
                'error' => 'Invalid socket ID format'
            ], 400);
        }

        if (!$this->isValidChannelName($channel)) {
            return response()->json([
                'error' => 'Invalid channel name format'
            ], 400);
        }

        try {
            $authResult = Channel::authorizeChannel($request, $channel);

            if ($authResult === false) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'You do not have permission to access this channel'
                ], 403);
            }

            $userData = is_array($authResult) ? $authResult : null;

            if (str_starts_with($channel, 'presence-') && !is_array($authResult)) {
                return response()->json([
                    'error' => 'Invalid presence channel configuration',
                    'message' => 'Presence channels must return user data'
                ], 500);
            }

            $auth = Broadcast::driver()->authenticate($socketId, $channel, $userData);

            return response()->json($auth);
        } catch (\Exception $e) {
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
        return (bool) preg_match('/^[a-zA-Z0-9\-\_\.]+$/', $channel);
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
                'email' => $user->email
            ],
        ];
    }
}
