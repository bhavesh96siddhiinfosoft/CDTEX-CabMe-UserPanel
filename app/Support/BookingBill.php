<?php

namespace App\Support;

/**
 * What a booked ride or rental actually costs to settle.
 *
 * The admin bills every completed booking the same way: take the amount
 * stored on it, subtract the coupon it was booked with, then add the tax
 * snapshot it carries. Both are stored ON the booking — the coupon as
 * `discount_type`, the taxes as `tax` — so the figure is fixed at booking
 * time and does not move with today's settings.
 *
 * The panel has to arrive at the same number before it charges anyone.
 */
class BookingBill
{
    /**
     * @param  float  $amount  the booking's own amount (a rental's is rewritten
     *                         by the driver's final-km call, extras included)
     * @param  mixed  $discountType  the booking's discount_type (json or array)
     * @param  mixed  $taxes  the booking's tax snapshot (json or array)
     * @return array{amount:float, discount:float, tax:float, total:float,
     *               taxes:array<int, array{libelle:string, type:string, value:float, amount:float}>}
     */
    public static function due(float $amount, mixed $discountType, mixed $taxes): array
    {
        $discount = self::discount($amount, self::rows($discountType));
        $base = max(0, round($amount - $discount, 2));
        $lines = self::taxLines($base, self::rows($taxes));

        return [
            'amount' => round($amount, 2),
            'discount' => $discount,
            'tax' => round(array_sum(array_column($lines, 'amount')), 2),
            'total' => round($base + array_sum(array_column($lines, 'amount')), 2),
            // Itemised, so a receipt can name each tax and say how it was
            // worked out rather than lumping them into one "Taxes" line.
            'taxes' => $lines,
        ];
    }

    /** Booking fields arrive as json from some endpoints and arrays from others. */
    private static function rows(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private static function discount(float $amount, array $discountType): float
    {
        if (! isset($discountType['value'])) {
            return 0.0;
        }

        $discount = strcasecmp((string) ($discountType['type'] ?? ''), 'Percentage') === 0
            ? (float) $discountType['value'] * $amount / 100
            : (float) $discountType['value'];

        return min(round($discount, 2), round($amount, 2));
    }

    /**
     * Each tax, with what it actually adds. The admin names them `libelle`
     * and types them "Fixed" or "Percentage" — with that capital P, so the
     * comparison has to be case-insensitive.
     *
     * @param  array<int, array{value?:mixed, type?:string, libelle?:string}>  $taxes
     * @return array<int, array{libelle:string, type:string, value:float, amount:float}>
     */
    private static function taxLines(float $base, array $taxes): array
    {
        $lines = [];

        foreach ($taxes as $tax) {
            if (! is_array($tax) || ! isset($tax['value'])) {
                continue;
            }

            $percentage = strcasecmp((string) ($tax['type'] ?? ''), 'Percentage') === 0;

            $lines[] = [
                'libelle' => (string) ($tax['libelle'] ?? $tax['title'] ?? $tax['name'] ?? ''),
                'type' => $percentage ? 'Percentage' : 'Fixed',
                'value' => (float) $tax['value'],
                'amount' => round($percentage ? (float) $tax['value'] * $base / 100 : (float) $tax['value'], 2),
            ];
        }

        return $lines;
    }
}
