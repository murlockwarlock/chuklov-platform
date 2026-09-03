<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use App\Modules\Scheduling\Domain\Models\LocationDay;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;

final readonly class BookingLocationSelection
{
    public function __construct(
        public ?WorkingLocation $workingLocation,
        public ?LocationDay $locationDay,
    ) {}
}
