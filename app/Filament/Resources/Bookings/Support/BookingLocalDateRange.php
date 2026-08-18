<?php

namespace App\Filament\Resources\Bookings\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class BookingLocalDateRange
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, ?string $from, ?string $until, string $timezone): Builder
    {
        if ($from === null && $until === null) {
            return $query;
        }

        $start = $from === null ? null : self::localStart($from, $timezone);
        $end = $until === null ? null : self::localStart($until, $timezone);

        if (($from !== null && $start === null)
            || ($until !== null && $end === null)
            || ($start !== null && $end !== null && $end->lessThan($start))) {
            return $query->whereKey(0);
        }

        if ($start !== null) {
            $query->where('starts_at', '>=', $start->utc());
        }

        if ($end !== null) {
            $query->where('starts_at', '<', $end->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    private static function localStart(string $date, string $timezone): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $date,
                new DateTimeZone($timezone),
            );

            return $parsed instanceof CarbonImmutable && $parsed->toDateString() === $date
                ? $parsed->startOfDay()
                : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
