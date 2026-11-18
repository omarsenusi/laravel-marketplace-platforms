<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

/**
 * Maps (driver key, topic pattern) to handler classes. First match wins;
 * no match falls back to NullHandler (log & mark processed).
 *
 * The service provider registers the install/authorize/uninstall/
 * subscription handlers; hosts add their own, e.g.:
 *
 *   app(WebhookHandlerRegistry::class)
 *       ->register('salla', 'app.store.authorize', AuthorizeStoreHandler::class);
 *
 * Patterns use fnmatch() syntax: 'app.*', 'order.*', '*'.
 */
class WebhookHandlerRegistry
{
    /**
     * @var list<array{driver: string, pattern: string, handler: class-string<WebhookHandler>}>
     */
    private array $registrations = [];

    /**
     * @param  class-string<WebhookHandler>  $handler
     */
    public function register(string $driverKey, string $topicPattern, string $handler): void
    {
        $this->registrations[] = [
            'driver' => $driverKey,
            'pattern' => $topicPattern,
            'handler' => $handler,
        ];
    }

    public function resolve(string $driverKey, ?string $topic): WebhookHandler
    {
        foreach ($this->registrations as $registration) {
            if ($registration['driver'] !== $driverKey) {
                continue;
            }

            if ($topic !== null && fnmatch($registration['pattern'], $topic)) {
                return app($registration['handler']);
            }
        }

        return app(NullHandler::class);
    }

    /**
     * @return list<array{driver: string, pattern: string, handler: class-string<WebhookHandler>}>
     */
    public function all(): array
    {
        return $this->registrations;
    }
}
