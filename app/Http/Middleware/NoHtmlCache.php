<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stop browsers reusing a stale HTML document.
 *
 * Every page here is session-specific and server-rendered, so there is nothing
 * to gain from caching the document — and plenty to lose: a page held from
 * before a deploy keeps running the old markup and stylesheet, which made a
 * fixed layout bug look unfixed long after it had been fixed.
 *
 * Only the document is affected. Vite's hashed assets stay cacheable.
 */
class NoHtmlCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');

        if ($type === '' || str_contains($type, 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
