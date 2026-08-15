<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;

final readonly class ScenarioEvaluationContext
{
    public function __construct(
        public ScenarioEvent $event,
        public ?Booking $booking,
        public ?Client $client,
        public ?ClientOnboarding $onboarding = null,
        public ?CarbonImmutable $evaluationEndsAt = null,
        public ?FinancialObligation $obligation = null,
    ) {}

    public function withEvaluationEndsAt(?CarbonImmutable $evaluationEndsAt): self
    {
        return new self(
            event: $this->event,
            booking: $this->booking,
            client: $this->client,
            onboarding: $this->onboarding,
            evaluationEndsAt: $evaluationEndsAt,
            obligation: $this->obligation,
        );
    }
}
