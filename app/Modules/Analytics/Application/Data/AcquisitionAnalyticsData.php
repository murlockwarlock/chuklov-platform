<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class AcquisitionAnalyticsData
{
    /** @param list<SourceBucket> $sources */
    public function __construct(
        public int $newClients,
        public array $sources,
    ) {}
}
