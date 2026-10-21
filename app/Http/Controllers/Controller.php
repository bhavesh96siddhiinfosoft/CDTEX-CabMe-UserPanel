<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Narrow a list by the filters in the query string.
     *
     * `q` matches any of $searchable (booking number, addresses, …), `from`
     * and `to` bound the date in $dateKeys, and `min`/`max` bound the value
     * in $amountKeys. An absent filter is simply not applied.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $searchable
     * @param  array<int, string>  $dateKeys
     * @param  array<int, string>  $amountKeys
     * @return array<int, array<string, mixed>>
     */
    protected function filterRows(array $rows, array $searchable, array $dateKeys = [], array $amountKeys = []): array
    {
        $query = trim((string) request()->query('q', ''));
        $from = trim((string) request()->query('from', ''));
        $to = trim((string) request()->query('to', ''));
        $min = request()->query('min');
        $max = request()->query('max');

        $first = function (array $row, array $keys) {
            foreach ($keys as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    return $row[$key];
                }
            }

            return null;
        };

        return array_values(array_filter($rows, function ($row) use ($query, $from, $to, $min, $max, $searchable, $dateKeys, $amountKeys, $first) {
            if (! is_array($row)) {
                return false;
            }

            if ($query !== '') {
                $haystack = '';

                foreach ($searchable as $key) {
                    $value = $row[$key] ?? null;
                    $haystack .= is_scalar($value) ? ' '.$value : '';
                }

                // Riders type the booking number with its hash; the stored
                // value may or may not carry one.
                if (! str_contains(mb_strtolower($haystack), mb_strtolower(ltrim($query, '#')))) {
                    return false;
                }
            }

            if (($from !== '' || $to !== '') && $dateKeys) {
                $raw = $first($row, $dateKeys);

                try {
                    $date = $raw ? \Illuminate\Support\Carbon::parse($raw) : null;
                } catch (\Throwable) {
                    $date = null;
                }

                if (! $date) {
                    return false;
                }

                if ($from !== '' && $date->lt(\Illuminate\Support\Carbon::parse($from)->startOfDay())) {
                    return false;
                }

                if ($to !== '' && $date->gt(\Illuminate\Support\Carbon::parse($to)->endOfDay())) {
                    return false;
                }
            }

            if (($min !== null && $min !== '') || ($max !== null && $max !== '')) {
                $amount = (float) $first($row, $amountKeys);

                if ($min !== null && $min !== '' && $amount < (float) $min) {
                    return false;
                }

                if ($max !== null && $max !== '' && $amount > (float) $max) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * One page of a list the admin returned whole.
     *
     * None of the admin's list endpoints accept a page or limit, so every
     * list arrives complete and is sliced here.
     *
     * @param  array<int, mixed>  $rows
     */
    protected function paginate(array $rows, string $path, int $perPage = 10, ?string $fragment = null): \Illuminate\Pagination\LengthAwarePaginator
    {
        $total = count($rows);
        $last = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) request()->query('page', 1)), $last);

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            $total,
            $perPage,
            $page,
            ['path' => $path],
        );

        // Filters must survive a page change.
        $paginator->appends(request()->except('page'));

        return $fragment ? $paginator->fragment($fragment) : $paginator;
    }

    //
}
