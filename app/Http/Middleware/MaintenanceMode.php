<?php

namespace App\Http\Middleware;

use App\Support\CabmeSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the maintenance page while the admin has the customer side switched
 * off (Settings → is_customer_maintenance_mode).
 *
 * The flag rides along with the rest of v1/settings/, which is cached, so it
 * takes effect within the settings TTL rather than instantly — a deliberate
 * trade against fetching settings on every request.
 *
 * Gateway webhooks are exempt. They are server-to-server calls about money
 * that is already moving: refusing one would lose a payment result for a
 * booking made before maintenance started.
 */
class MaintenanceMode
{
    /** Paths that must keep working while the site is down. */
    private const ALWAYS_OPEN = [
        'webhooks/*',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(CabmeSettings::class);

        if ($request->is(self::ALWAYS_OPEN) || ! $settings->underMaintenance()) {
            return $next($request);
        }

        // 503 with Retry-After so crawlers treat this as temporary and do not
        // drop the site from their index.
        return response()
            ->view('maintenance', ['settings' => $settings], 503)
            ->header('Retry-After', 3600);
    }
}
