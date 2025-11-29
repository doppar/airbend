<?php

namespace Doppar\Airbend\Console\Commands;

use Phaseolies\Console\Schedule\Command;

class MakeEventCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'make:event {name}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Create a new Event class';

    /**
     * Execute the console command.
     *
     * @return int
     */
    protected function handle(): int
    {
        return $this->executeWithTiming(function () {
            $name = $this->argument('name');
            $parts = explode('/', $name);
            $className = array_pop($parts);

            // Ensure class name ends with Event
            if (!str_ends_with($className, 'Event')) {
                $className .= 'Event';
            }

            $namespace = 'App\\Events' . (count($parts) > 0 ? '\\' . implode('\\', $parts) : '');
            $parts[] = $className;

            $filePath = base_path('app/Events/' . implode(DIRECTORY_SEPARATOR, $parts) . '.php');

            // Check if Event already exists
            if (file_exists($filePath)) {
                $this->displayError('Event already exists at:');
                $this->line('<fg=white>' . str_replace(base_path(), '', $filePath) . '</>');
                return Command::FAILURE;
            }

            // Create directory if needed
            $directoryPath = dirname($filePath);
            if (!is_dir($directoryPath)) {
                mkdir($directoryPath, 0755, true);
            }

            // Generate and save Event class
            $channel = strtolower(
                preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('Event', '', $className))
            );

            $content = $this->generateEventContent($namespace, $className, $channel);
            file_put_contents($filePath, $content);

            $this->displaySuccess('Event created successfully');
            $this->line('<fg=yellow>📦 File:</> <fg=white>' . str_replace(base_path(), '', $filePath) . '</>');
            $this->newLine();
            $this->line('<fg=yellow>⚙️  Class:</> <fg=white>' . $className . '</>');

            return Command::SUCCESS;
        });
    }

    /**
     * Generate Event class content.
     */
    protected function generateEventContent(string $namespace, string $className, string $channel): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;

class {$className} extends BaseBroadcastEvent
{
    /**
     * Create a new event instance.
     */
    public function __construct(){}

    /**
     * Define the channels the event should broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        return [];
    }

    /**
     * Additional data to include with the broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [];
    }
}

PHP;
    }
}
