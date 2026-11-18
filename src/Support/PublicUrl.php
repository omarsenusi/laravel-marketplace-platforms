<?php

namespace Omarsenusi\MarketplacePlatforms\Support;

/**
 * Absolute URLs the OUTSIDE world uses (merchant emails, OAuth
 * redirect_uri, webhook/loader URLs pasted into Salla/Zid, links shown
 * inside the marketplace iframe) — always built on APP_URL.
 *
 * url()/route() use the CURRENT request's scheme + host instead. Behind
 * a tunnel or proxy (e.g. the local vhost answering for the public
 * domain) that yields http://local-host links: mixed-content blocks,
 * OAuth redirect_uri mismatches, and dead links in emails.
 */
final class PublicUrl
{
    public static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function to(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return self::base().'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return self::to(route($name, $parameters, false));
    }
}
