<?php

namespace App\Console\Commands;

use App\Services\Cabme\CabmeClient;
use App\Support\CabmeSettings;
use Illuminate\Console\Command;

/**
 * Setup diagnostic. The most common failure by far is CABME_API_KEY not
 * matching the admin's APP_KEY byte for byte, which surfaces as a bare 401
 * on every call — this names that explicitly.
 */
class CabmeCheck extends Command
{
    protected $signature = 'cabme:check';

    protected $description = 'Verify this panel can reach and authenticate against the cabme-admin API';

    public function handle(CabmeClient $client, CabmeSettings $settings): int
    {
        $this->line('');
        $this->line('  Base URL : '.(config('cabme.base_url') ?: '<not set>'));
        $this->line('  API key  : '.(config('cabme.api_key')
            ? substr(config('cabme.api_key'), 0, 12).'…'
            : '<not set>'));
        $this->line('');

        if (! config('cabme.api_key')) {
            $this->error('  CABME_API_KEY is empty. Copy APP_KEY from the cabme-admin .env verbatim.');

            return self::FAILURE;
        }

        // v1/settings/ is a guest endpoint, so this isolates the api key check
        // from anything to do with a rider access token.
        $settings->flush();
        $result = $client->guest('GET', 'v1/settings/');

        if ($result->unauthorized()) {
            $this->error('  401 Unauthorized — the admin rejected CABME_API_KEY.');
            $this->line('  It must equal the admin\'s APP_KEY exactly, including the "base64:" prefix.');

            return self::FAILURE;
        }

        if ($result->failed()) {
            $this->error('  Could not reach the API: '.$result->messageOr('unknown error'));
            $this->line('  Check CABME_API_URL points at the admin\'s public/ directory.');

            return self::FAILURE;
        }

        $this->info('  Connected to cabme-admin.');
        $this->line('');
        $this->line('  App name  : '.$settings->appName());
        $this->line('  Currency  : '.$settings->currency().' ('.$settings->decimals().' dp)');
        $this->line('  Distance  : '.$settings->distanceUnit());
        $this->line('  Maps      : '.($settings->usesGoogleMaps() ? 'Google' : 'OpenStreetMap / OSRM'));
        $this->line('  Services  : '.(implode(', ', $settings->activeServices()) ?: 'none enabled'));
        $this->line('');

        return self::SUCCESS;
    }
}
