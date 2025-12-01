<?php

namespace Doppar\Airbend;

use Phaseolies\Providers\ServiceProvider;
use Doppar\Airbend\Broadcasting\BroadcastManager;
use Doppar\Airbend\Console\Commands\MakeEventCommand;
use Doppar\Airbend\Console\Commands\WebSocketStartCommand;

class AirbendServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfig(__DIR__ . '/../config/airbend.php', 'airbend');

        $this->registerBroadcastManager();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutes(__DIR__ . '/../routes/airbend.php');

        if (file_exists(base_path('routes/channels.php'))) {
            require base_path('routes/channels.php');
        }

        $this->publishes([
            __DIR__ . '/../config/airbend.php' => config_path('airbend.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../assets' => public_path('vendor/airbend/assets'),
        ], 'public');

        $this->publishes([
            __DIR__ . '/../routes/channels.php' => base_path('routes/channels.php'),
        ], 'views');

        $this->commands([
            WebSocketStartCommand::class,
            MakeEventCommand::class
        ]);
    }

    /**
     * Register the broadcast manager
     *
     * @return void
     */
    protected function registerBroadcastManager(): void
    {
        $this->app->singleton('broadcast', function ($app) {
            return new BroadcastManager();
        });
    }
}
