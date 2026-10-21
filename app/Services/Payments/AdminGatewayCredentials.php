<?php

namespace App\Services\Payments;

use App\Services\Cabme\CatalogApi;
use Illuminate\Support\Facades\Cache;

/**
 * Looks a gateway's settings row up in the ADMIN's payment settings — the
 * same single source the mobile apps read. Credentials are fetched
 * server-to-server, cached briefly, and never sent to a browser (each driver
 * decides which single field, if any, is public).
 */
class AdminGatewayCredentials
{
    public function __construct(private readonly CatalogApi $catalog)
    {
    }

    /**
     * The enabled settings row whose libelle contains $fragment, or null.
     * A miss is not cached, so fixing credentials in the admin shows up on
     * the next attempt.
     */
    public function row(string $fragment): ?array
    {
        return Cache::remember('cabme.gateway.'.$fragment, 300, function () use ($fragment) {
            $body = $this->catalog->paymentMethods()->raw;

            foreach ($body as $bucketKey => $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }

                foreach (array_is_list($bucket) ? $bucket : [$bucket] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    // Some buckets omit libelle (Mercadopago) — the bucket's
                    // own key still names the gateway.
                    $label = strtolower((string) ($row['libelle'] ?? $bucketKey));

                    if (str_contains($label, $fragment) && ($row['isEnabled'] ?? '') === 'true') {
                        return $row;
                    }
                }
            }

            return null;
        }) ?: null;
    }
}
