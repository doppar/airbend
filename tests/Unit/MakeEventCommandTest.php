<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Console\Commands\MakeEventCommand;
use PHPUnit\Framework\TestCase;

class MakeEventCommandTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . '/doppar-airbend-command-' . bin2hex(random_bytes(5));
        mkdir($this->tempRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function testMakeEventCommandSupportsBackslashesAndRelativeOutput(): void
    {
        $command = new class($this->tempRoot) extends MakeEventCommand
        {
            public array $capturedLines = [];
            public array $capturedSuccesses = [];

            public function __construct(private string $tempRoot)
            {
                parent::__construct();
            }

            protected function argument($key = null)
            {
                return $key === 'name' ? 'Realtime\\OrderPlaced' : null;
            }

            protected function line(string $string, ?string $style = null): void
            {
                $this->capturedLines[] = $string;
            }

            protected function newLine($count = 1): void
            {
            }

            protected function displaySuccess(string $message): void
            {
                $this->capturedSuccesses[] = $message;
            }

            protected function executeWithTiming(callable $callback): int
            {
                $result = $callback();

                return is_int($result) ? $result : 0;
            }

            protected function generatedFilePath(string $baseDirectory, string $name, string $extension = '.php'): string
            {
                $normalizedName = $this->normalizeGeneratedName($name);
                $relativePath = trim($baseDirectory, '/\\');

                if ($normalizedName !== '') {
                    $relativePath .= '/' . $normalizedName;
                }

                return $this->tempRoot . '/' . $relativePath . $extension;
            }

            protected function relativePath(string $path, ?string $basePath = null): string
            {
                $normalizedPath = str_replace('\\', '/', $path);
                $normalizedBase = rtrim(str_replace('\\', '/', $this->tempRoot), '/');

                return substr($normalizedPath, strlen($normalizedBase) + 1);
            }
        };

        $result = $command->handle();
        $file = $this->tempRoot . '/app/Events/Realtime/OrderPlacedEvent.php';
        $contents = (string) file_get_contents($file);

        $this->assertSame(0, $result);
        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace App\\Events\\Realtime;', $contents);
        $this->assertStringContainsString('class OrderPlacedEvent extends BaseBroadcastEvent', $contents);
        $this->assertContains('Event created successfully', $command->capturedSuccesses);
        $this->assertContains(
            '<fg=yellow>📦 File:</> <fg=white>app/Events/Realtime/OrderPlacedEvent.php</>',
            $command->capturedLines
        );
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
