<?php

namespace Omarsenusi\MarketplacePlatforms;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Billing\NullSubscriptionApplier;
use Omarsenusi\MarketplacePlatforms\Console\PurgeWebhookDeliveries;
use Omarsenusi\MarketplacePlatforms\Console\RefreshDueTokens;
use Omarsenusi\MarketplacePlatforms\Console\SendClaimReminders;
use Omarsenusi\MarketplacePlatforms\Contracts\AppliesSubscriptions as AppliesSubscriptionsContract;
use Omarsenusi\MarketplacePlatforms\Drivers\DriverRegistry;
use Omarsenusi\MarketplacePlatforms\Drivers\SallaDriver;
use Omarsenusi\MarketplacePlatforms\Drivers\ZidDriver;
use Omarsenusi\MarketplacePlatforms\Listeners\MarkClaimedOnPasswordReset;
use Omarsenusi\MarketplacePlatforms\Services\WebhookDeliveryService;
use Omarsenusi\MarketplacePlatforms\Services\WebhookIngress;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\SallaAuthorizeHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\SallaInstalledHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\SallaSubscriptionHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\SallaUninstalledHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\SallaUpdatedHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\ZidInstallHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\ZidSubscriptionHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Handlers\ZidUninstallHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\NullHandler;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\BasicAuthPasswordStrategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\BearerTokenStrategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\CompositeAnyStrategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\HmacSha256Strategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\NoneStrategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\PlainHeaderStrategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\Strategies\StaticHeaderStrategy;
use Omarsenusi\MarketplacePlatforms\Webhooks\Verification\StrategyRegistry;
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

class PlatformsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/platforms.php', 'platforms');

        $this->app->singleton(DriverRegistry::class, function (): DriverRegistry {
            $registry = new DriverRegistry;
            $registry->register(new SallaDriver);
            $registry->register(new ZidDriver);

            return $registry;
        });

        $this->app->singleton(StrategyRegistry::class, function (): StrategyRegistry {
            $registry = new StrategyRegistry;
            $registry->register(new HmacSha256Strategy);
            $registry->register(new PlainHeaderStrategy);
            $registry->register(new BearerTokenStrategy);
            $registry->register(new StaticHeaderStrategy);
            $registry->register(new BasicAuthPasswordStrategy);
            $registry->register(new CompositeAnyStrategy);
            $registry->register(new NoneStrategy);

            return $registry;
        });

        $this->app->singleton(WebhookHandlerRegistry::class, function (): WebhookHandlerRegistry {
            $registry = new WebhookHandlerRegistry;

            // Install/authorize/uninstall. Unknown topics fall back to
            // NullHandler (which still fires PlatformWebhookReceived).
            $registry->register('salla', 'app.store.authorize', SallaAuthorizeHandler::class);
            $registry->register('salla', 'app.installed', SallaInstalledHandler::class);
            $registry->register('salla', 'app.uninstalled', SallaUninstalledHandler::class);
            $registry->register('salla', 'app.updated', SallaUpdatedHandler::class);
            $registry->register('zid', 'app.market.application.install', ZidInstallHandler::class);
            $registry->register('zid', 'app.market.application.authorized', ZidInstallHandler::class);
            $registry->register('zid', 'app.market.application.uninstall', ZidUninstallHandler::class);

            // Marketplace-subscription events.
            $registry->register('salla', 'app.subscription.started', SallaSubscriptionHandler::class);
            $registry->register('salla', 'app.subscription.renewed', SallaSubscriptionHandler::class);
            $registry->register('salla', 'app.subscription.expired', SallaSubscriptionHandler::class);
            $registry->register('salla', 'app.subscription.canceled', SallaSubscriptionHandler::class);
            $registry->register('salla', 'app.trial.started', SallaSubscriptionHandler::class);
            $registry->register('salla', 'app.trial.expired', SallaSubscriptionHandler::class);
            $registry->register('salla', 'app.trial.canceled', SallaSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.active', ZidSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.renew', ZidSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.upgrade', ZidSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.warning', ZidSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.suspended', ZidSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.expired', ZidSubscriptionHandler::class);
            $registry->register('zid', 'app.market.subscription.refunded', ZidSubscriptionHandler::class);

            return $registry;
        });

        $this->app->singleton(WebhookIngress::class, fn (): WebhookIngress => new WebhookIngress(
            $this->app->make(StrategyRegistry::class),
        ));

        $this->app->singleton(WebhookDeliveryService::class, fn (): WebhookDeliveryService => new WebhookDeliveryService);

        $this->app->singleton(StoreApiGateway::class);

        // Host subscription handling. The default only records the
        // snapshot; bind your own implementation to change host plans.
        $this->app->singleton(AppliesSubscriptionsContract::class, NullSubscriptionApplier::class);

        // NullHandler is registered explicitly so hosts can decorate
        // or replace the unhandled-topic fallback.
        $this->app->bind(NullHandler::class, NullHandler::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'platforms');

        // Public ingress: no session, no CSRF (marketplace servers post
        // here directly). OAuth/claim routes need the session → 'web'
        // group (see file).
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/platforms.php');

        // A null-password (marketplace-installed) user completing the
        // host's forgot-password flow counts as claiming.
        Event::listen(PasswordReset::class, MarkClaimedOnPasswordReset::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeWebhookDeliveries::class, RefreshDueTokens::class, SendClaimReminders::class]);

            $this->publishes([
                __DIR__.'/../config/platforms.php' => config_path('platforms.php'),
            ], 'platforms-config');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('platforms:webhooks:purge')->hourly()->withoutOverlapping();
            $schedule->command('platforms:tokens:refresh-due')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('platforms:claims:remind')->daily()->withoutOverlapping();
        });

        Route::model('platform', Models\Platform::class);
        Route::model('connection', Models\PlatformConnection::class);
        Route::model('mapping', Models\PlatformPlanMapping::class);
    }
}
