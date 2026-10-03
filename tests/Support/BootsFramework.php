<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Support;

use Phaseolies\Config\Config;
use Phaseolies\DI\Container;

/**
 * Boots just enough of the framework (config + a recording logger) for Airbend unit tests.
 */
trait BootsFramework
{
    /** @var array<int, array{level: string, message: string}> */
    protected array $logged = [];

    protected function bootFramework(string $secret = 'unit-test-secret', string $env = 'testing'): void
    {
        $container = new class extends Container {
            public function storagePath(string $path = ''): string
            {
                return sys_get_temp_dir() . ($path !== '' ? '/' . $path : '');
            }

            public function basePath(string $path = ''): string
            {
                return sys_get_temp_dir() . ($path !== '' ? '/' . $path : '');
            }
        };
        Container::setInstance($container);

        $logged = &$this->logged;
        $container->instance('log', new class($logged) {
            /** @param array<int, array{level: string, message: string}> $logged */
            public function __construct(private array &$logged) {}

            /** @param array<int, mixed> $args */
            public function __call(string $level, array $args): void
            {
                $this->logged[] = ['level' => $level, 'message' => (string) ($args[0] ?? '')];
            }
        });

        Config::set('app', ['env' => $env]);
        Config::set('airbend', [
            'default' => 'workerman',
            'connections' => ['workerman' => ['driver' => 'workerman']],
            'websocket' => ['max_connections' => 1000, 'connection_timeout' => 180, 'heartbeat_interval' => 30],
            'authorize' => ['app_key' => 'test-key', 'app_secret' => $secret],
        ]);

        \Doppar\Airbend\Configuration\ConfigurationManager::clearCache();
    }
}
