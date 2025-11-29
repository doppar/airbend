<?php

namespace Doppar\Airbend\Broadcasting;

class Channel
{
    /**
     * Registered channel authorizers
     *
     * @var array
     */
    protected static array $authorizers = [];

    /**
     * Register a channel authorization callback
     *
     * @param string $pattern Channel pattern with parameters
     * @param \Closure $callback Authorization callback
     * @return void
     */
    public static function authorize(string $pattern, \Closure $callback): void
    {
        static::$authorizers[$pattern] = $callback;
    }

    /**
     * Get authorization callback for a channel
     *
     * @param string $channel
     * @return \Closure|null
     */
    public static function getAuthorizer(string $channel): ?\Closure
    {
        foreach (static::$authorizers as $pattern => $callback) {
            if (static::matchesPattern($channel, $pattern)) {
                return $callback;
            }
        }

        return null;
    }

    /**
     * Check if channel matches pattern
     *
     * @param string $channel
     * @param string $pattern
     * @return bool
     */
    protected static function matchesPattern(string $channel, string $pattern): bool
    {
        $regex = static::buildRegexFromPattern($pattern);

        return (bool) preg_match($regex, $channel);
    }

    /**
     * Extract parameters from channel name using pattern
     *
     * @param string $channel
     * @param string $pattern
     * @return array
     */
    public static function extractParameters(string $channel, string $pattern): array
    {
        $regex = static::buildRegexFromPattern($pattern);

        if (preg_match($regex, $channel, $matches)) {
            return array_filter($matches, function ($key) {
                return !is_numeric($key);
            }, ARRAY_FILTER_USE_KEY);
        }

        return [];
    }

    /**
     * Build a regular expression from a channel pattern
     *
     * @param string $pattern
     * @return string
     */
    protected static function buildRegexFromPattern(string $pattern): string
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^.]+)', $pattern);

        return '#^' . $regex . '$#';
    }

    /**
     * Authorize a channel subscription
     *
     * @param \Phaseolies\Http\Request $request
     * @param string $channel
     * @return bool|array
     */
    public static function authorizeChannel($request, string $channel): bool|array
    {
        $authorizer = static::getAuthorizer($channel);

        if (!$authorizer) {
            if (str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-')) {
                return false;
            }

            return true;
        }

        foreach (static::$authorizers as $pattern => $callback) {
            if (static::matchesPattern($channel, $pattern)) {
                $params = static::extractParameters($channel, $pattern);

                return $callback($request, ...array_values($params));
            }
        }

        return false;
    }

    /**
     * Clear all registered authorizers (useful for testing)
     *
     * @return void
     */
    public static function clearAuthorizers(): void
    {
        static::$authorizers = [];
    }

    /**
     * Get all registered authorizers
     *
     * @return array
     */
    public static function getAuthorizers(): array
    {
        return static::$authorizers;
    }
}