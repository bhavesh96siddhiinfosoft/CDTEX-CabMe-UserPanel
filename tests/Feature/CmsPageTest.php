<?php

namespace Tests\Feature;

use App\Support\CabmeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pages authored under CMS Pages in the admin are served at /page/{slug} and
 * nowhere else — the panel's own pages keep their own content. Publishing a
 * page in the admin is all it takes for it to go live.
 */
class CmsPageTest extends TestCase
{
    private function fakeAdmin(?string $body, array $settings = []): void
    {
        config()->set('cabme.base_url', 'https://admin.test');
        config()->set('cabme.api_key', 'base64:test-key');

        Cache::flush();
        $this->app->forgetInstance(CabmeSettings::class);

        Http::preventStrayRequests();
        Http::fake([
            'https://admin.test/api/v1/cms-page/*' => $body === null
                ? Http::response(['success' => 'failed', 'data' => null], 404)
                : Http::response([
                    'success' => 'success',
                    'data' => [
                        'cms_id' => 57,
                        'cms_name' => 'Refund policy',
                        'cms_slug' => 'refund-policy',
                        'cms_desc' => $body,
                    ],
                ]),
            'https://admin.test/api/v1/cms-pages/' => Http::response([
                'success' => 'success',
                'data' => [['cms_id' => 57, 'cms_name' => 'Refund policy', 'cms_slug' => 'refund-policy']],
            ]),
            'https://admin.test/api/v1/settings/' => Http::response([
                'success' => 'success',
                'data' => array_merge([
                    'title' => 'CabMe',
                    'currency' => '$',
                    'active_services' => ['ride', 'parcel', 'rental'],
                ], $settings),
            ]),
            '*' => Http::response(['success' => 'success', 'data' => []]),
        ]);
    }

    public function test_a_published_page_is_reachable_by_slug(): void
    {
        $this->fakeAdmin('<h2>Refunds</h2><p>Within 7 days.</p>');

        $this->get('/page/refund-policy')
            ->assertOk()
            ->assertSee('Refunds', false)
            ->assertSee('Within 7 days.', false);
    }

    public function test_an_unknown_slug_is_a_404(): void
    {
        $this->fakeAdmin(null);

        $this->get('/page/nothing-here')->assertNotFound();
    }

    /** A published page needs no code change to be found. */
    public function test_published_pages_are_listed_in_the_footer(): void
    {
        $this->fakeAdmin('<p>Anything</p>');

        $this->get('/contact')
            ->assertOk()
            ->assertSee('/page/refund-policy', false)
            ->assertSee('Refund policy', false);
    }

    /** CMS content belongs at /page/{slug}, not on the panel's own pages. */
    public function test_contact_does_not_render_cms_content(): void
    {
        $this->fakeAdmin('<p>CMS body that must not appear here</p>');

        $this->get('/contact')
            ->assertOk()
            ->assertDontSee('CMS body that must not appear here', false);
    }

    /** The admin sends these as contact_us_*, which is what the page reads. */
    public function test_contact_shows_the_details_from_settings(): void
    {
        $this->fakeAdmin(null, [
            'contact_us_email' => 'info@siddhiinfosoft.com',
            'contact_us_phone' => '9909012996',
            'default_country_phonecode' => '+91',
            'contact_us_address' => 'Siddhi Infosoft, C-407, Ganesh meridian, Ahmedabad',
        ]);

        $this->get('/contact')
            ->assertOk()
            ->assertSee('info@siddhiinfosoft.com', false)
            ->assertSee('+91 9909012996', false)
            ->assertSee('tel:+919909012996', false)
            ->assertSee('Ganesh meridian', false);
    }
}
