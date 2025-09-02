<?php

namespace Zap;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Zap\Commands\SendScheduleNotifications;
use Zap\Services\ConflictDetectionService;
use Zap\Services\ScheduleExecutionService;
use Zap\Services\ScheduleService;
use Zap\Services\ValidationService;

class ZapServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zap.php', 'zap');

        // Register core services
        $this->app->singleton(ScheduleService::class);
        $this->app->singleton(ConflictDetectionService::class);
        $this->app->singleton(ValidationService::class);
        $this->app->singleton(ScheduleExecutionService::class);

        // Register the facade
        $this->app->bind('zap', ScheduleService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/zap.php' => config_path('zap.php'),
            ], 'zap-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'zap-migrations');

            $this->commands([
                SendScheduleNotifications::class,
            ]);
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('zap:notify')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            'zap',
            ScheduleService::class,
            ConflictDetectionService::class,
            ValidationService::class,
            ScheduleExecutionService::class,
        ];
    }
}
