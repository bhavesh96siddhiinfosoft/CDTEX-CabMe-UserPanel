<?php

namespace App\Services;

use App\Support\CabmeSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Road distance and duration between two points.
 *
 * The fare endpoints price a trip from its distance, so this is the only piece
 * of routing the panel needs. Provider follows the admin's map_for_application
 * setting: Google Directions when a key is configured, OSRM otherwise — the
 * same either/or the admin and dispatcher use for geocoding.
 */
class GeoService
{
    public function __construct(private readonly CabmeSettings $settings)
    {
    }

    /**
     * @return array{distance: float, duration: float, unit: string, geometry: ?array<int, array{0: float, 1: float}>}|null
     *         distance in the admin's configured unit, duration in minutes,
     *         geometry as simplified [lat, lng] points for drawing the road
     *         path on the map (null when the provider sent none).
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng, array $via = []): ?array
    {
        $leg = $this->settings->usesGoogleMaps()
            ? $this->viaGoogle($fromLat, $fromLng, $toLat, $toLng, $via)
            : $this->viaOsrm($fromLat, $fromLng, $toLat, $toLng, $via);

        if (! $leg) {
            return null;
        }

        [$metres, $seconds, $geometry] = $leg;

        $unit = $this->settings->distanceUnit();
        $distance = strtolower($unit) === 'mile' || strtolower($unit) === 'miles'
            ? $metres / 1609.344
            : $metres / 1000;

        return [
            'distance' => round($distance, 2),
            'duration' => round($seconds / 60, 0),
            'unit' => $unit,
            'geometry' => $geometry,
        ];
    }

    /** @return array{0: float, 1: float, 2: ?array}|null [metres, seconds, geometry] */
    private function viaGoogle(float $fromLat, float $fromLng, float $toLat, float $toLng, array $via = []): ?array
    {
        $params = [
            'origin' => "$fromLat,$fromLng",
            'destination' => "$toLat,$toLng",
            'key' => $this->settings->googleMapKey(),
        ];

        // Multi-stop rides route through each stop in order.
        if ($via !== []) {
            $params['waypoints'] = implode('|', array_map(
                fn ($p) => $p[0].','.$p[1], $via,
            ));
        }

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/directions/json', $params);
        } catch (ConnectionException $e) {
            Log::warning('Google Directions unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $legs = $response->json('routes.0.legs');

        if (! is_array($legs) || $legs === []) {
            Log::warning('Google Directions returned no route', ['status' => $response->json('status')]);

            return null;
        }

        // With waypoints the route has one leg per segment — sum them all.
        $metres = 0.0;
        $seconds = 0.0;
        foreach ($legs as $leg) {
            $metres += (float) ($leg['distance']['value'] ?? 0);
            $seconds += (float) ($leg['duration']['value'] ?? 0);
        }

        // overview_polyline is Google's simplified shape of the whole route.
        $encoded = (string) $response->json('routes.0.overview_polyline.points', '');

        return [$metres, $seconds, $encoded !== '' ? $this->decodePolyline($encoded) : null];
    }

    /** @return array{0: float, 1: float, 2: ?array}|null [metres, seconds, geometry] */
    private function viaOsrm(float $fromLat, float $fromLng, float $toLat, float $toLng, array $via = []): ?array
    {
        // Public demo server — rate limited. Point OSRM_URL at your own
        // instance before this sees real traffic.
        $base = rtrim(env('OSRM_URL', 'https://router.project-osrm.org'), '/');

        // OSRM chains coordinates: from;stop1;stop2;to — lng,lat order.
        $points = array_merge(
            [[$fromLat, $fromLng]],
            $via,
            [[$toLat, $toLng]],
        );
        $chain = implode(';', array_map(fn ($p) => $p[1].','.$p[0], $points));

        try {
            // 'simplified' keeps the payload small while still following roads.
            $response = Http::timeout(15)
                ->get("$base/route/v1/driving/$chain", [
                    'overview' => 'simplified',
                    'geometries' => 'geojson',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('OSRM unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            return null;
        }

        // GeoJSON is [lng, lat]; Leaflet wants [lat, lng].
        $geometry = null;
        $coordinates = $route['geometry']['coordinates'] ?? null;
        if (is_array($coordinates) && $coordinates !== []) {
            $geometry = array_map(
                fn ($p) => [round((float) $p[1], 5), round((float) $p[0], 5)],
                $coordinates,
            );
        }

        return [(float) ($route['distance'] ?? 0), (float) ($route['duration'] ?? 0), $geometry];
    }

    /**
     * Decode Google's encoded polyline format into [lat, lng] points.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            foreach (['lat', 'lng'] as $which) {
                $shift = 0;
                $result = 0;

                do {
                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1f) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20 && $index < $length);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $which === 'lat' ? $lat += $delta : $lng += $delta;
            }

            $points[] = [round($lat * 1e-5, 5), round($lng * 1e-5, 5)];
        }

        return $points;
    }

    /**
     * The country a point sits in, as the admin spells it.
     *
     * Taxes are stored per country, and the admin picks a booking's taxes by
     * reverse-geocoding its pickup (Google when a key is set, Nominatim
     * otherwise). The panel has to resolve the same country to preview and
     * charge the same total. Cached for a day — a pickup area does not move —
     * and an empty string on any failure, which is exactly the admin's
     * "country unknown" case.
     */
    public function country(float $lat, float $lng): string
    {
        // ~1km grid: neighbouring pickups share a lookup.
        $key = sprintf('cabme.geo.country.%.2f,%.2f', $lat, $lng);

        return Cache::remember($key, 86400, function () use ($lat, $lng) {
            try {
                if ($this->settings->usesGoogleMaps() && $this->settings->googleMapKey() !== '') {
                    $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                        'latlng' => $lat.','.$lng,
                        'key' => $this->settings->googleMapKey(),
                    ]);

                    foreach ($response->json('results.0.address_components') ?? [] as $component) {
                        if (in_array('country', $component['types'] ?? [], true)) {
                            return (string) ($component['long_name'] ?? '');
                        }
                    }

                    return '';
                }

                $response = Http::withHeaders(['User-Agent' => config('app.name', 'Cabme')])
                    ->timeout(8)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat,
                        'lon' => $lng,
                        'format' => 'json',
                    ]);

                return (string) ($response->json('address.country') ?? '');
            } catch (\Throwable $e) {
                Log::warning('Reverse geocode for country failed', ['error' => $e->getMessage()]);

                return '';
            }
        });
    }
    /** Straight-line fallback, in the admin's unit. Used only when routing fails. */
    public function haversine(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earth = strtolower($this->settings->distanceUnit()) === 'mile' ? 3958.8 : 6371.0;

        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
