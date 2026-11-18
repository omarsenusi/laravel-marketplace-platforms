<?php

namespace Omarsenusi\MarketplacePlatforms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frameable launch responses for the marketplace dashboards.
 *
 * Sets our own Content-Security-Policy (the app CSP shape, but with
 * frame-ancestors from platforms.embedded.frame_ancestors) so the
 * app's AddSecurityHeaders leaves the response alone, and strips
 * X-Frame-Options (it would otherwise deny the iframe even when the
 * CSP allows it). Applied to the launch route only.
 */
final class AllowEmbeddedFrames
{
    private const CSP_TEMPLATE = <<<'CSP'
        default-src 'self';
        script-src 'self' 'unsafe-inline' 'unsafe-eval' https:;
        style-src 'self' 'unsafe-inline' https:;
        img-src 'self' data: https: blob:;
        font-src 'self' data: https:;
        connect-src 'self' https: wss:;
        frame-src 'self' https:;
        media-src 'self' https:;
        object-src 'none';
        base-uri 'self';
        form-action 'self';
        frame-ancestors {ancestors};
        CSP;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $ancestors = trim((string) config(
            'platforms.embedded.frame_ancestors',
            "'self' https://s.salla.sa https://*.salla.sa https://*.salla.dev https://web.zid.sa https://*.zid.sa",
        ));

        if ($ancestors === '') {
            $ancestors = "'self'";
        }

        $csp = str_replace('{ancestors}', $ancestors, self::CSP_TEMPLATE);
        $csp = trim((string) preg_replace('/\s+/', ' ', $csp));

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
