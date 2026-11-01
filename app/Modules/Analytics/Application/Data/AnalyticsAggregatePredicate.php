<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class AnalyticsAggregatePredicate
{
    public const string UnknownValue = '__unknown__';

    public function __construct(
        public AnalyticsAggregateDimension $dimension,
        public int|string|null $value = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public string $displayLabel = '',
    ) {}
}
