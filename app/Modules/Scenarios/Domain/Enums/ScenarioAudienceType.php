<?php

namespace App\Modules\Scenarios\Domain\Enums;

enum ScenarioAudienceType: string
{
    case Client = 'client';
    case Members = 'members';
    case Roles = 'roles';
    case AssignedSpecialist = 'assigned_specialist';
}
