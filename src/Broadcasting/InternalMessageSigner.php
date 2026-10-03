<?php

namespace Doppar\Airbend\Broadcasting;

use Doppar\Airbend\Configuration\ConfigurationManager;

/**
 * Signs and verifies messages exchanged between the application and the
 * internal broadcast server, so only holders of the app secret can broadcast.
 */
class InternalMessageSigner
{
    /**
     * Seconds a signed message stays valid, limiting replay of a captured frame
     *
     * @var int
     */
    public const MAX_AGE = 300;

    /**
     * Wrap a payload in a signed envelope
     *
     * @param array<string, mixed> $payload
     * @return string JSON envelope without the line terminator
     * @throws \JsonException
     */
    public static function sign(array $payload): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return json_encode([
            'payload' => $body,
            'signature' => hash_hmac('sha256', $body, ConfigurationManager::appSecret()),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Verify an envelope and return its payload
     *
     * @param string $raw
     * @return array<string, mixed>|null Null when the envelope is malformed, forged or expired
     */
    public static function verify(string $raw): ?array
    {
        $envelope = json_decode($raw, true, 8);

        if (
            !is_array($envelope)
            || !isset($envelope['payload'], $envelope['signature'])
            || !is_string($envelope['payload'])
            || !is_string($envelope['signature'])
        ) {
            return null;
        }

        $expected = hash_hmac('sha256', $envelope['payload'], ConfigurationManager::appSecret());

        if (!hash_equals($expected, $envelope['signature'])) {
            return null;
        }

        $payload = json_decode($envelope['payload'], true, 32);

        if (!is_array($payload)) {
            return null;
        }

        $timestamp = $payload['timestamp'] ?? null;

        if (!is_int($timestamp) || abs(time() - $timestamp) > self::MAX_AGE) {
            return null;
        }

        return $payload;
    }
}
