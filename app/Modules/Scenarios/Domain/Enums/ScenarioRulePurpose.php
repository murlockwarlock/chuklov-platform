<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioRulePurpose: string
{
    case Service = 'service';
    case Transactional = 'transactional';
}
