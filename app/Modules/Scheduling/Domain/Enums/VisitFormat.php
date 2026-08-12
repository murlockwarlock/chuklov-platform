<?php

namespace App\Modules\Scheduling\Domain\Enums;

enum VisitFormat: string
{
    case Office = 'office';
    case HomeVisit = 'home';
    case Online = 'online';
}
