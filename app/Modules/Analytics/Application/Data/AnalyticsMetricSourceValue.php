<?php

namespace App\Modules\Analytics\Application\Data;

use App\Modules\Analytics\Domain\Enums\AnalyticsMetricSourceKind;

final readonly class AnalyticsMetricSourceValue
{
    public function __construct(
        public string $key,
        public AnalyticsMetricSourceKind $kind,
        public ?float $value,
        public ?string $currency = null,
    ) {}
}
