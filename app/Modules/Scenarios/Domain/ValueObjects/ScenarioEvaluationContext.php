<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Models\Booking;

final readonly class ScenarioEvaluationContext
{
    public function __construct(
        public ScenarioEvent $event,
        public ?Booking $booking,
        public ?Client $client,
    ) {}
}
