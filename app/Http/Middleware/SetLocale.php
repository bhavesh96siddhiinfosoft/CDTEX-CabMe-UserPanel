<?php

namespace App\Http\Middleware;

use App\Services\Cabme\CatalogApi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Applies the rider's chosen interface language.
 *
 * Which languages exist is the admin's call (its language screen, via
 * v1/language/); the translations themselves live in this panel's lang/
 * files. The choice is kept in the session — while the language list is
 * unavailable (API down) a previously chosen locale keeps working rather
 * than flipping the interface back to English.
 */
class SetLocale
{
    public function __construct(private readonly CatalogApi $catalog)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $languages = $this->catalog->languages();
        $codes = array_column($languages, 'code');

        $locale = $request->session()->get('locale');

        $usable = is_string($locale)
            && preg_match('/^[a-z]{2}$/', $locale)
            && ($codes === [] || in_array($locale, $codes, true));

        if ($usable) {
            app()->setLocale($locale);
        }

        $current = collect($languages)->firstWhere('code', app()->getLocale());

        // Shared once per request — every layout needs the direction and the
        // header needs the switcher list.
        View::share('appLanguages', $languages);
        View::share('isRtl', (bool) ($current['rtl'] ?? false));

        return $next($request);
    }
}
