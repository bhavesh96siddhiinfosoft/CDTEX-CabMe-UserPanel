<?php

namespace App\Support;

/**
 * The taxes a booking will actually be charged.
 *
 * The admin stores a tax snapshot on every booking — the active taxes for the
 * pickup's country, or all of them when it cannot tell the country — and adds
 * them to the bill at completion. A pay-first booking (parcel, rental) is
 * charged up front, so the panel has to arrive at the same figure the admin
 * will: same rows, same order of operations.
 */
class Taxes
{
    public function __construct(private readonly CabmeSettings $settings)
    {
    }

    /**
     * Active taxes for a country, as the admin picks them: the country's own
     * rows, falling back to every active tax when the country is unknown.
     *
     * @return array<int, array{libelle:string, value:float, type:string}>
     */
    public function forCountry(string $country = ''): array
    {
        $rows = $this->settings->get('tax');
        $rows = is_array($rows) ? $rows : [];

        $active = array_values(array_filter(
            $rows,
            fn ($tax) => is_array($tax) && ($tax['statut'] ?? 'yes') === 'yes',
        ));

        if ($country !== '') {
            $matching = array_values(array_filter(
                $active,
                fn ($tax) => strcasecmp((string) ($tax['country'] ?? ''), $country) === 0,
            ));

            // No rows for this country means no tax there — not "charge them
            // all", which is the admin's behaviour only when the country
            // itself could not be resolved.
            $active = $matching;
        }

        return array_map(fn ($tax) => [
            'libelle' => (string) ($tax['libelle'] ?? ''),
            'value' => (float) ($tax['value'] ?? 0),
            'type' => (string) ($tax['type'] ?? 'Fixed'),
        ], $active);
    }

    /**
     * What those taxes add to an amount. Percentages apply to the amount as
     * given — the admin passes the already-discounted figure — and fixed
     * amounts are added as they are.
     *
     * @param  array<int, array{value:float, type:string}>  $taxes
     */
    public function on(float $amount, array $taxes): float
    {
        $total = 0.0;

        foreach ($taxes as $tax) {
            $total += strcasecmp((string) ($tax['type'] ?? ''), 'Percentage') === 0
                ? (float) $tax['value'] * $amount / 100
                : (float) $tax['value'];
        }

        return round($total, 2);
    }
}
