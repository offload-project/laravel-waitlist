<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OffloadProject\Waitlist\Console\SyncMailingListCommand;
use OffloadProject\Waitlist\Events\WaitlistEntryAdded;
use OffloadProject\Waitlist\Events\WaitlistEntryVerified;
use OffloadProject\Waitlist\Listeners\SubscribeEntryToMailingList;
use OffloadProject\Waitlist\MailingList\MailingListManager;

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

        $this->app->alias('waitlist', WaitlistService::class);

        $this->app->singleton(MailingListManager::class, function ($app) {
            return new MailingListManager();
        });

        $this->app->alias(MailingListManager::class, 'waitlist.mailing-list');
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncMailingListCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/waitlist.php' => config_path('waitlist.php'),
            ], 'waitlist-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'waitlist-migrations');
        }
    }

    private function registerRoutes(): void
    {
        if (! config('waitlist.routes.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('waitlist.routes.prefix', 'waitlist'),
            'middleware' => config('waitlist.routes.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/routes/waitlist.php');
        });
    }

    private function registerListeners(): void
    {
        Event::listen(WaitlistEntryAdded::class, SubscribeEntryToMailingList::class);
        Event::listen(WaitlistEntryVerified::class, SubscribeEntryToMailingList::class);
    }
}
