<?php

namespace App\Modules\Scenarios\Domain\Contracts;

use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;

interface ScenarioRecipientResolver
{
    /** @return list<ScenarioRecipient> */
    public function resolve(ScenarioRule $rule, ScenarioEvent $event): array;
}
