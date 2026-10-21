<?php

namespace App\Providers;

use App\Services\Cabme\CabmeClient;
use App\Services\Cabme\SupportApi;
use App\Support\CabmeSettings;
use App\Support\Rider;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rider identity lives in the session, so bind the concrete store.
        $this->app->bind(Session::class, fn ($app) => $app['session.store']);

        // One instance per request: Rider caches nothing, but CabmeSettings
        // memoises the settings payload so a page render makes one call at most.
        $this->app->scoped(Rider::class);
        $this->app->scoped(CabmeSettings::class);
        $this->app->scoped(CabmeClient::class);
    }

    public function boot(): void
    {
        // Every view needs the rider (header/avatar) and settings (currency,
        // which services to show in nav), so share them rather than threading
        // them through every controller.
        View::composer('*', function ($view) {
            $view->with([
                'rider' => $this->app->make(Rider::class),
                'settings' => $this->app->make(CabmeSettings::class),
            ]);
        });

        // The footer lists whatever the admin publishes under CMS Pages, so a
        // new page needs no code change here. Cached, since every page in the
        // panel renders this footer.
        View::composer('partials.footer', function ($view) {
            $pages = Cache::get('cabme.cms_pages');

            if ($pages === null) {
                try {
                    $result = $this->app->make(SupportApi::class)->cmsPages();
                    $pages = $result->failed() || ! is_array($result->data) ? [] : $result->data;
                } catch (\Throwable) {
                    // A decorative menu must never take a page render down.
                    $pages = [];
                }

                // A failed call must not pin an empty menu for the full TTL.
                Cache::put('cabme.cms_pages', $pages, $pages === [] ? 10 : (int) config('cabme.settings_ttl'));
            }

            $view->with('cmsPages', $pages);
        });
    }
}
