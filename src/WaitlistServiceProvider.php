<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist;

use Illuminate\Support\ServiceProvider;

final class WaitlistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/waitlist.php',
            'waitlist'
        );

        $this->app->singleton('waitlist', function ($app) {
            return new WaitlistService();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/waitlist.php' => config_path('waitlist.php'),
            ], 'waitlist-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'waitlist-migrations');
        }
    }
}
