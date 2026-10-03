<?php

namespace Doppar\Airbend\Console\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Airbend\Broadcasting\WebSocketServer;
use Doppar\Airbend\Configuration\ConfigurationManager;

class WebSocketStartCommand extends Command
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    protected $name = 'websocket:start
                      {--host= : The host to bind the WebSocket server (defaults to websocket.host)}
                      {--port= : The port to bind the WebSocket server (defaults to websocket.port)}
                      {--ssl : Enable SSL/TLS for secure connections}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the Doppar WebSocket broadcasting server';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $host = $this->option('host') ?: ConfigurationManager::get('websocket.host', '127.0.0.1');
        $port = (int) ($this->option('port') ?: ConfigurationManager::get('websocket.port', 6001));
        $ssl = (bool) $this->option('ssl') || (bool) ConfigurationManager::get('websocket.ssl', false);

        $this->newLine();
        $this->displayInfo("Starting Doppar WebSocket Server...");
        $this->newLine();

        $this->line("Host: {$host}");
        $this->line("Port: {$port}");
        $this->line("SSL: " . ($ssl ? 'Enabled' : 'Disabled'));
        $this->newLine();

        try {
            global $argv;
            $argv = [
                'websocket-server',
                'start',
            ];
            $_SERVER['argv'] = $argv;

            $server = new WebSocketServer($host, $port, $ssl);

            $this->displaySuccess("WebSocket server started successfully!");
            $this->line("Listening on ws" . ($ssl ? 's' : '') . "://{$host}:{$port}");
            $this->newLine();
            $this->line("Press Ctrl+C to stop the server");
            $this->newLine();

            $server->run();

            return 0;
        } catch (\Throwable $e) {
            $this->displayError("Failed to start WebSocket server: " . $e->getMessage());
            return 1;
        }
    }
}
